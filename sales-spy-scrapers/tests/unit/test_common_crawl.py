import json

import httpx
import pytest
import respx

from sales_spy_scrapers.sources.common_crawl import CommonCrawlSource


@pytest.mark.asyncio
@respx.mock
async def test_common_crawl_source_normalizes_deduplicates_and_bounds_records() -> None:
    endpoint = "https://index.commoncrawl.org/CC-MAIN-2026-30-index"
    respx.get(endpoint).mock(
        return_value=httpx.Response(
            200,
            text="\n".join(
                [
                    json.dumps(
                        {"url": "https://www.Example.com/a", "digest": "one", "mime": "text/html"}
                    ),
                    json.dumps(
                        {"url": "https://example.com/b", "digest": "two", "mime": "text/html"}
                    ),
                    json.dumps({"url": "https://second.example.org", "digest": "three"}),
                    "invalid-json",
                ]
            ),
        )
    )

    records = await CommonCrawlSource("CC-MAIN-2026-30", max_records=2).discover("*.example")

    assert [record.domain for record in records] == ["example.com", "second.example.org"]
    assert records[0].source == "common_crawl"
    assert records[0].source_external_id == "one"


def test_common_crawl_source_rejects_invalid_index() -> None:
    with pytest.raises(ValueError, match="must start"):
        __import__("asyncio").run(CommonCrawlSource("invalid").discover("*.com"))


@pytest.mark.asyncio
@respx.mock
async def test_common_crawl_source_enforces_response_byte_limit() -> None:
    endpoint = "https://index.commoncrawl.org/CC-MAIN-2026-30-index"
    respx.get(endpoint).mock(
        return_value=httpx.Response(200, text='{"url":"https://example.com"}\n')
    )

    with pytest.raises(ValueError, match="byte limit"):
        await CommonCrawlSource(
            "CC-MAIN-2026-30",
            max_response_bytes=5,
        ).discover("*.example")
