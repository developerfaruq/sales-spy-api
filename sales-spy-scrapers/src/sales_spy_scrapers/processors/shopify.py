import json
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any, Protocol
from urllib.parse import urljoin

from sales_spy_scrapers.domain.models import ProductRecord
from sales_spy_scrapers.domain.normalization import money_to_cents
from sales_spy_scrapers.infrastructure.http_client import FetchedPage


class JsonFetcher(Protocol):
    async def fetch_json(self, url: str) -> FetchedPage: ...


@dataclass(frozen=True, slots=True)
class ShopifyCatalogResult:
    products: list[ProductRecord]
    complete: bool


class ShopifyCatalogCrawler:
    def __init__(
        self,
        http_client: JsonFetcher,
        page_limit: int = 10,
        product_limit: int = 2500,
        aggregate_byte_limit: int = 25_000_000,
    ) -> None:
        if page_limit < 1 or page_limit > 100:
            raise ValueError("page_limit must be between 1 and 100")
        self._http = http_client
        self._page_limit = page_limit
        self._product_limit = product_limit
        self._aggregate_byte_limit = aggregate_byte_limit

    async def crawl(self, store_url: str) -> ShopifyCatalogResult:
        products: list[ProductRecord] = []
        seen_ids: set[str] = set()
        complete = False
        received_bytes = 0
        for page_number in range(1, self._page_limit + 1):
            url = urljoin(
                store_url.rstrip("/") + "/", f"products.json?limit=250&page={page_number}"
            )
            page = await self._http.fetch_json(url)
            received_bytes += len(page.content)
            if received_bytes > self._aggregate_byte_limit:
                raise ValueError("Shopify catalog exceeded the aggregate byte limit")
            payload = json.loads(page.content)
            raw_products = payload.get("products") if isinstance(payload, dict) else None
            if not isinstance(raw_products, list):
                raise ValueError("Shopify products endpoint returned an invalid payload")
            if not raw_products:
                complete = True
                break
            for raw_product in raw_products:
                if not isinstance(raw_product, dict):
                    continue
                product = parse_shopify_product(raw_product, store_url)
                identity = product.external_id or product.handle
                if identity and identity not in seen_ids:
                    seen_ids.add(identity)
                    products.append(product)
                    if len(products) >= self._product_limit:
                        return ShopifyCatalogResult(products=products, complete=False)
            if len(raw_products) < 250:
                complete = True
                break
        return ShopifyCatalogResult(products=products, complete=complete)


def parse_shopify_product(raw: dict[str, Any], store_url: str) -> ProductRecord:
    variants = [item for item in raw.get("variants", []) if isinstance(item, dict)]
    images = [item for item in raw.get("images", []) if isinstance(item, dict)]
    prices: list[int] = [
        price for item in variants if (price := _money(item.get("price"))) is not None
    ]
    compare_prices: list[int] = [
        price for item in variants if (price := _money(item.get("compare_at_price"))) is not None
    ]
    handle = _text(raw.get("handle"))
    external_id = _text(raw.get("id"))
    image_urls = [url for image in images if (url := _text(image.get("src")))]
    available_values = [item.get("available") for item in variants]
    inventory_values = [
        value for item in variants if isinstance((value := item.get("inventory_quantity")), int)
    ]

    return ProductRecord(
        external_id=external_id,
        handle=handle,
        title=_text(raw.get("title")) or "Untitled product",
        description=_text(raw.get("body_html")),
        vendor=_text(raw.get("vendor")),
        product_type=_text(raw.get("product_type")),
        status="active",
        price_cents=min(prices) if prices else None,
        compare_at_price_cents=max(compare_prices) if compare_prices else None,
        minimum_variant_price_cents=min(prices) if prices else None,
        maximum_variant_price_cents=max(prices) if prices else None,
        variant_count=len(variants),
        is_available=any(value is True for value in available_values) if variants else True,
        inventory_quantity=sum(inventory_values) if inventory_values else None,
        product_url=urljoin(store_url.rstrip("/") + "/", f"products/{handle}") if handle else None,
        primary_image_url=image_urls[0] if image_urls else None,
        tags=_tags(raw.get("tags")),
        images=image_urls,
        variants=variants,
        source_payload={
            "id": raw.get("id"),
            "handle": raw.get("handle"),
            "options": raw.get("options", []),
        },
        published_at=_datetime(raw.get("published_at")),
        source_created_at=_datetime(raw.get("created_at")),
        source_updated_at=_datetime(raw.get("updated_at")),
        last_seen_at=datetime.now(UTC),
    )


def _text(value: object) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text[:255] if text else None


def _money(value: object) -> int | None:
    return money_to_cents(str(value)) if value not in (None, "") else None


def _tags(value: object) -> list[str]:
    if isinstance(value, str):
        return [tag.strip() for tag in value.split(",") if tag.strip()]
    if isinstance(value, list):
        return [str(tag).strip() for tag in value if str(tag).strip()]
    return []


def _datetime(value: object) -> datetime | None:
    if not isinstance(value, str) or not value:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00"))
