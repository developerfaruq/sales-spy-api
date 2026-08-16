import asyncio
import ipaddress
import math
import random
import socket
import time
from collections import defaultdict
from contextlib import suppress
from dataclasses import dataclass
from datetime import UTC, datetime
from email.utils import parsedate_to_datetime
from types import TracebackType
from urllib.parse import urljoin, urlsplit, urlunsplit
from urllib.robotparser import RobotFileParser

import httpx
import structlog

from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.domain.errors import (
    FetchError,
    ResponseTooLargeError,
    RetryableFetchError,
    RobotsDeniedError,
    UnsafeTargetError,
    UnsupportedContentError,
)

log = structlog.get_logger(__name__)


@dataclass(frozen=True, slots=True)
class FetchedPage:
    url: str
    status_code: int
    headers: dict[str, str]
    content: bytes
    elapsed_ms: int


class HostRateLimiter:
    def __init__(self, requests_per_second: float) -> None:
        self._interval = 1 / requests_per_second
        self._locks: dict[str, asyncio.Lock] = defaultdict(asyncio.Lock)
        self._last_request: dict[str, float] = {}

    async def wait(self, host: str) -> None:
        async with self._locks[host]:
            now = time.monotonic()
            delay = self._interval - (now - self._last_request.get(host, 0))
            if delay > 0:
                await asyncio.sleep(delay)
            self._last_request[host] = time.monotonic()


class RobotsPolicy:
    def __init__(
        self,
        client: httpx.AsyncClient,
        user_agent: str,
        cache_seconds: int,
        max_response_bytes: int,
        limiter: HostRateLimiter,
    ) -> None:
        self._client = client
        self._user_agent = user_agent
        self._cache_seconds = cache_seconds
        self._max_response_bytes = max_response_bytes
        self._limiter = limiter
        self._cache: dict[str, tuple[float, RobotFileParser | None]] = {}
        self._locks: dict[str, asyncio.Lock] = defaultdict(asyncio.Lock)

    async def allowed(self, url: str) -> bool:
        parsed = urlsplit(url)
        origin = f"{parsed.scheme}://{parsed.netloc}"
        async with self._locks[origin]:
            cached = self._cache.get(origin)
            if cached and time.monotonic() - cached[0] < self._cache_seconds:
                parser = cached[1]
            else:
                parser = await self._load(origin)
                self._cache[origin] = (time.monotonic(), parser)

        return parser is None or parser.can_fetch(self._user_agent, url)

    async def _load(self, origin: str) -> RobotFileParser | None:
        try:
            status_code, content = await self._get_robots_response(f"{origin}/robots.txt")
            if status_code in {401, 403}:
                parser = RobotFileParser()
                parser.parse(["User-agent: *", "Disallow: /"])
                return parser
            if status_code == 404:
                return None
            if status_code == 429:
                raise RetryableFetchError("robots.txt returned 429")
            if status_code >= 500:
                raise RetryableFetchError(f"robots.txt returned {status_code}")
            if status_code >= 400:
                return None
            parser = RobotFileParser()
            parser.set_url(f"{origin}/robots.txt")
            parser.parse(content.decode("utf-8", errors="replace").splitlines())
            return parser
        except httpx.HTTPError as exc:
            raise FetchError(f"robots.txt request failed for {origin}") from exc

    async def _get_robots_response(self, url: str) -> tuple[int, bytes]:
        current_url = url
        for redirect_count in range(4):
            parsed = urlsplit(current_url)
            if not parsed.hostname:
                raise FetchError("robots.txt redirect has no hostname")
            request_url, request_headers, extensions = await _pinned_request(current_url)
            await self._limiter.wait(parsed.hostname)
            async with self._client.stream(
                "GET",
                request_url,
                headers=request_headers,
                extensions=extensions,
            ) as response:
                if not response.is_redirect:
                    content = await _read_bounded_response(
                        response,
                        min(self._max_response_bytes, 1_048_576),
                    )
                    return response.status_code, content
                if redirect_count == 3:
                    raise FetchError("robots.txt exceeded the redirect limit")
                location = response.headers.get("location")
                if not location:
                    raise FetchError("robots.txt redirect has no location")
                current_url = urljoin(current_url, location)
        raise FetchError("robots.txt redirect handling failed")


class SafeHttpClient:
    def __init__(
        self,
        settings: Settings,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        timeout = httpx.Timeout(
            settings.read_timeout_seconds,
            connect=settings.connect_timeout_seconds,
        )
        limits = httpx.Limits(
            max_connections=settings.max_concurrency,
            max_keepalive_connections=settings.max_concurrency,
        )
        self._settings = settings
        self._client = httpx.AsyncClient(
            timeout=timeout,
            limits=limits,
            follow_redirects=False,
            headers={
                "User-Agent": settings.user_agent,
                "Accept": "text/html,application/xhtml+xml,application/json",
                "Accept-Encoding": "identity",
                # No `Connection: close`: it made max_keepalive_connections dead
                # configuration and forced a fresh TCP + TLS handshake for every
                # page of a same-host crawl (a Shopify catalogue is up to 100).
            },
            transport=transport,
        )
        self._limiter = HostRateLimiter(settings.requests_per_second)
        self._robots = RobotsPolicy(
            self._client,
            settings.user_agent,
            settings.robots_cache_seconds,
            settings.max_response_bytes,
            self._limiter,
        )
        self._global_semaphore = asyncio.Semaphore(settings.max_concurrency)
        self._host_semaphores: dict[str, asyncio.Semaphore] = defaultdict(
            lambda: asyncio.Semaphore(settings.per_host_concurrency)
        )

    async def __aenter__(self) -> "SafeHttpClient":
        await self._client.__aenter__()
        return self

    async def __aexit__(
        self,
        exc_type: type[BaseException] | None,
        exc_value: BaseException | None,
        traceback: TracebackType | None,
    ) -> None:
        await self._client.__aexit__(exc_type, exc_value, traceback)

    async def fetch_html(self, url: str) -> FetchedPage:
        return await self._fetch(url, ("html", "xhtml"))

    async def fetch_json(self, url: str) -> FetchedPage:
        return await self._fetch(url, ("application/json", "text/json"))

    async def _fetch(self, url: str, accepted_content_types: tuple[str, ...]) -> FetchedPage:
        parsed = urlsplit(url)
        if parsed.scheme not in {"http", "https"} or not parsed.hostname:
            raise FetchError("only HTTP and HTTPS URLs are supported")
        async with self._global_semaphore:
            if not await self._robots.allowed(url):
                raise RobotsDeniedError(f"robots.txt disallows crawling {url}")
            return await self._fetch_with_retries(url, accepted_content_types)

    async def _fetch_with_retries(
        self,
        url: str,
        accepted_content_types: tuple[str, ...],
    ) -> FetchedPage:
        last_error: Exception | None = None
        for attempt in range(1, self._settings.max_attempts + 1):
            try:
                return await self._fetch_once(url, accepted_content_types)
            except (FetchError, httpx.TransportError, httpx.TimeoutException) as exc:
                last_error = exc
                if not _is_retryable(exc):
                    raise
                if attempt == self._settings.max_attempts:
                    break
                retry_after = (
                    exc.retry_after_seconds if isinstance(exc, RetryableFetchError) else None
                )
                delay = retry_after or (
                    min(8.0, 0.5 * (2 ** (attempt - 1))) * random.uniform(0.5, 1.5)
                )
                log.warning(
                    "fetch_retry_scheduled",
                    url=url,
                    attempt=attempt,
                    delay_seconds=round(delay, 3),
                    error=str(exc),
                )
                await asyncio.sleep(delay)
        raise FetchError(str(last_error or "fetch failed")) from last_error

    async def _fetch_once(
        self,
        url: str,
        accepted_content_types: tuple[str, ...],
    ) -> FetchedPage:
        started = time.monotonic()
        current_url = url
        for redirect_count in range(self._settings.max_redirects + 1):
            parsed = urlsplit(current_url)
            if not parsed.hostname:
                raise FetchError("redirect target has no hostname")
            request_url, request_headers, extensions = await _pinned_request(current_url)
            try:
                async with self._host_semaphores[parsed.hostname]:
                    if current_url != url and not await self._robots.allowed(current_url):
                        raise RobotsDeniedError(f"robots.txt disallows crawling {current_url}")
                    await self._limiter.wait(parsed.hostname)
                    async with self._client.stream(
                        "GET",
                        request_url,
                        headers=request_headers,
                        extensions=extensions,
                    ) as response:
                        if response.is_redirect:
                            if redirect_count == self._settings.max_redirects:
                                raise FetchError("response exceeded the redirect limit")
                            location = response.headers.get("location")
                            if not location:
                                raise FetchError("redirect response has no location")
                            current_url = urljoin(current_url, location)
                            continue
                        if response.status_code == 429 or response.status_code >= 500:
                            raise RetryableFetchError(
                                f"upstream server returned {response.status_code}",
                                _retry_after_seconds(response.headers.get("retry-after")),
                            )
                        content, status_code, headers = await self._read_response(response)
                        final_url = current_url
                        break
            except ResponseTooLargeError:
                raise
            except (httpx.HTTPError, ValueError) as exc:
                raise FetchError(str(exc)) from exc
        else:
            raise FetchError("redirect handling failed")

        content_type = headers.get("content-type", "").lower()
        if not any(expected in content_type for expected in accepted_content_types):
            raise UnsupportedContentError(f"unsupported content type: {content_type or 'unknown'}")
        return FetchedPage(
            url=final_url,
            status_code=status_code,
            headers=headers,
            content=content,
            elapsed_ms=int((time.monotonic() - started) * 1000),
        )

    async def _read_response(
        self,
        response: httpx.Response,
    ) -> tuple[bytes, int, dict[str, str]]:
        content = await _read_bounded_response(response, self._settings.max_response_bytes)
        return content, response.status_code, dict(response.headers)


async def _resolve_public_host(host: str, port: int | None) -> str:
    if host.lower() in {"localhost", "localhost.localdomain"}:
        raise UnsafeTargetError("local network targets are not allowed")
    loop = asyncio.get_running_loop()
    try:
        addresses = await loop.getaddrinfo(
            host,
            port or 443,
            family=socket.AF_UNSPEC,
            type=socket.SOCK_STREAM,
        )
    except socket.gaierror as exc:
        raise FetchError(f"DNS resolution failed for {host}") from exc
    if not addresses:
        raise FetchError(f"DNS resolution returned no addresses for {host}")
    for address in {item[4][0] for item in addresses}:
        ip = ipaddress.ip_address(address)
        if not ip.is_global:
            raise UnsafeTargetError(f"non-public target address is not allowed: {ip}")
    return sorted({item[4][0] for item in addresses})[0]


async def _pinned_request(url: str) -> tuple[str, dict[str, str], dict[str, str]]:
    parsed = urlsplit(url)
    if not parsed.hostname:
        raise FetchError("request URL has no hostname")
    address = await _resolve_public_host(parsed.hostname, parsed.port)
    address_host = f"[{address}]" if ":" in address else address
    port = parsed.port
    netloc = f"{address_host}:{port}" if port else address_host
    request_url = urlunsplit((parsed.scheme, netloc, parsed.path, parsed.query, parsed.fragment))
    default_port = 443 if parsed.scheme == "https" else 80
    host_header = (
        parsed.hostname if not port or port == default_port else f"{parsed.hostname}:{port}"
    )
    return request_url, {"Host": host_header}, {"sni_hostname": parsed.hostname}


def _is_retryable(exc: Exception) -> bool:
    return not isinstance(
        exc,
        (ResponseTooLargeError, RobotsDeniedError, UnsupportedContentError, UnsafeTargetError),
    )


async def _read_bounded_response(response: httpx.Response, maximum_bytes: int) -> bytes:
    content_length = response.headers.get("content-length")
    if content_length and int(content_length) > maximum_bytes:
        raise ResponseTooLargeError("response exceeds configured size limit")
    content_encoding = response.headers.get("content-encoding", "identity").lower()
    if content_encoding not in {"", "identity"}:
        raise UnsupportedContentError("compressed responses are not accepted")
    if response.is_stream_consumed:
        if len(response.content) > maximum_bytes:
            raise ResponseTooLargeError("response exceeds configured size limit")
        return response.content
    chunks: list[bytes] = []
    size = 0
    async for chunk in response.aiter_raw():
        size += len(chunk)
        if size > maximum_bytes:
            raise ResponseTooLargeError("response exceeds configured size limit")
        chunks.append(chunk)
    return b"".join(chunks)


def _retry_after_seconds(value: str | None) -> float | None:
    if not value:
        return None
    with suppress(ValueError):
        seconds = float(value)
        if math.isfinite(seconds):
            return min(max(seconds, 0.0), 300.0)
    with suppress(TypeError, ValueError):
        retry_at = parsedate_to_datetime(value)
        now = datetime.now(UTC)
        if retry_at.tzinfo is None:
            retry_at = retry_at.replace(tzinfo=UTC)
        return min(max((retry_at - now).total_seconds(), 0.0), 300.0)
    return None
