import asyncio
import json
from collections.abc import AsyncIterator
from dataclasses import dataclass
from typing import Any

import httpx

from sales_spy_scrapers.domain.models import DiscoveryRecord
from sales_spy_scrapers.domain.normalization import canonical_url, normalize_domain


@dataclass(frozen=True, slots=True)
class CommonCrawlSource:
    index_name: str
    # Must come from settings.user_agent. Hardcoding it here meant
    # SCRAPER_USER_AGENT had no effect on Common Crawl requests, so the bot
    # identified itself with a domain the operator may not own.
    user_agent: str = "SalesSpyBot/1.0"
    timeout_seconds: float = 30.0
    max_records: int = 10_000
    max_response_bytes: int = 20_000_000
    max_line_bytes: int = 1_000_000

    async def discover(self, url_pattern: str) -> list[DiscoveryRecord]:
        if not self.index_name.startswith("CC-MAIN-"):
            raise ValueError("Common Crawl index name must start with CC-MAIN-")
        if self.max_records < 1 or self.max_records > 100_000:
            raise ValueError("max_records must be between 1 and 100000")

        endpoint = f"https://index.commoncrawl.org/{self.index_name}-index"
        discoveries: dict[str, DiscoveryRecord] = {}
        async with asyncio.timeout(self.timeout_seconds):
            async with (
                httpx.AsyncClient(
                    timeout=self.timeout_seconds,
                    headers={
                        "User-Agent": self.user_agent,
                        "Accept-Encoding": "identity",
                    },
                ) as client,
                client.stream(
                    "GET",
                    endpoint,
                    params={
                        "url": url_pattern,
                        "output": "json",
                        "filter": "status:200",
                        "collapse": "urlkey",
                    },
                ) as response,
            ):
                response.raise_for_status()
                if response.headers.get("content-encoding", "identity") not in {"", "identity"}:
                    raise ValueError("compressed Common Crawl responses are not accepted")
                received = 0
                buffer = bytearray()
                chunks = [response.content] if response.is_stream_consumed else response.aiter_raw()
                if isinstance(chunks, list):

                    async def loaded_chunks() -> AsyncIterator[bytes]:
                        for chunk in chunks:
                            yield chunk

                    chunk_stream = loaded_chunks()
                else:
                    chunk_stream = chunks
                async for chunk in chunk_stream:
                    received += len(chunk)
                    if received > self.max_response_bytes:
                        raise ValueError("Common Crawl response exceeded the byte limit")
                    buffer.extend(chunk)
                    if len(buffer) > self.max_line_bytes and b"\n" not in buffer:
                        raise ValueError("Common Crawl line exceeded the byte limit")
                    while b"\n" in buffer and len(discoveries) < self.max_records:
                        line, _, remainder = buffer.partition(b"\n")
                        buffer = bytearray(remainder)
                        record = _parse_line(line.decode("utf-8", errors="replace"))
                        if record is not None:
                            discoveries.setdefault(record.domain, record)
                    if len(discoveries) >= self.max_records:
                        break
                if buffer and len(discoveries) < self.max_records:
                    if len(buffer) > self.max_line_bytes:
                        raise ValueError("Common Crawl line exceeded the byte limit")
                    record = _parse_line(buffer.decode("utf-8", errors="replace"))
                    if record is not None:
                        discoveries.setdefault(record.domain, record)
        return list(discoveries.values())


def _parse_line(line: str) -> DiscoveryRecord | None:
    try:
        payload: Any = json.loads(line)
    except json.JSONDecodeError:
        return None
    if not isinstance(payload, dict):
        return None
    url = payload.get("url")
    if not isinstance(url, str):
        return None
    try:
        domain = normalize_domain(url)
        normalized_url = canonical_url(url)
    except ValueError:
        return None
    digest = payload.get("digest")
    return DiscoveryRecord(
        domain=domain,
        canonical_url=normalized_url,
        source="common_crawl",
        source_external_id=str(digest) if digest else None,
        source_payload={
            key: payload[key]
            for key in ("timestamp", "mime", "digest", "filename", "offset", "length")
            if key in payload
        },
    )
