import json
from datetime import UTC

import pytest

from sales_spy_scrapers.infrastructure.http_client import FetchedPage
from sales_spy_scrapers.processors.shopify import ShopifyCatalogCrawler, parse_shopify_product


class FakeJsonClient:
    def __init__(self, payloads: list[dict[str, object]]) -> None:
        self.payloads = payloads
        self.urls: list[str] = []

    async def fetch_json(self, url: str) -> FetchedPage:
        self.urls.append(url)
        payload = self.payloads.pop(0)
        return FetchedPage(
            url, 200, {"content-type": "application/json"}, json.dumps(payload).encode(), 1
        )


def test_parse_shopify_product_normalizes_prices_and_variants() -> None:
    product = parse_shopify_product(
        {
            "id": 10,
            "handle": "running-shoe",
            "title": "Running Shoe",
            "vendor": "Acme",
            "tags": "running, featured",
            "variants": [
                {"id": 1, "price": "19.99", "compare_at_price": "29.99", "available": True},
                {"id": 2, "price": "24.50", "compare_at_price": None, "available": False},
            ],
            "images": [{"src": "https://cdn.example.com/shoe.jpg"}],
            "published_at": "2026-08-01T10:00:00Z",
        },
        "https://example.com",
    )

    assert product.external_id == "10"
    assert product.price_cents == 1999
    assert product.minimum_variant_price_cents == 1999
    assert product.maximum_variant_price_cents == 2450
    assert product.compare_at_price_cents == 2999
    assert product.variant_count == 2
    assert product.is_available is True
    assert product.tags == ["running", "featured"]
    assert product.published_at is not None
    assert product.published_at.tzinfo is not None
    assert product.last_seen_at.tzinfo is UTC


@pytest.mark.asyncio
async def test_catalog_crawler_paginates_and_deduplicates() -> None:
    first_page = [
        {"id": index, "title": f"Product {index}", "variants": []} for index in range(250)
    ]
    second_page = [
        {"id": 249, "title": "Duplicate", "variants": []},
        {"id": 250, "title": "Product 250", "variants": []},
    ]
    client = FakeJsonClient([{"products": first_page}, {"products": second_page}])

    result = await ShopifyCatalogCrawler(client, page_limit=3).crawl("https://example.com")

    assert len(result.products) == 251
    assert result.complete is True
    assert client.urls == [
        "https://example.com/products.json?limit=250&page=1",
        "https://example.com/products.json?limit=250&page=2",
    ]


def test_catalog_page_limit_is_bounded() -> None:
    with pytest.raises(ValueError, match="between 1 and 100"):
        ShopifyCatalogCrawler(FakeJsonClient([]), page_limit=101)


@pytest.mark.asyncio
async def test_catalog_at_page_limit_is_marked_incomplete() -> None:
    full_page = [{"id": index, "title": f"Product {index}", "variants": []} for index in range(250)]
    client = FakeJsonClient([{"products": full_page}])

    result = await ShopifyCatalogCrawler(client, page_limit=1).crawl("https://example.com")

    assert len(result.products) == 250
    assert result.complete is False


@pytest.mark.asyncio
async def test_catalog_enforces_aggregate_byte_limit() -> None:
    client = FakeJsonClient([{"products": [{"id": 1, "title": "Large", "variants": []}]}])

    with pytest.raises(ValueError, match="aggregate byte limit"):
        await ShopifyCatalogCrawler(
            client,
            aggregate_byte_limit=5,
        ).crawl("https://example.com")
