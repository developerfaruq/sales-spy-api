from dataclasses import dataclass

from bs4 import BeautifulSoup

from sales_spy_scrapers.domain.enums import CommercePlatform


@dataclass(frozen=True, slots=True)
class PlatformSignals:
    platform: str | None
    cms: str | None
    commerce: CommercePlatform | None
    confidence: float


def detect_platform(html: str, headers: dict[str, str] | None = None) -> PlatformSignals:
    soup = BeautifulSoup(html, "lxml")
    return detect_platform_from_soup(soup, html.lower(), headers)


def detect_platform_from_soup(
    soup: BeautifulSoup,
    lower_html: str,
    headers: dict[str, str] | None = None,
) -> PlatformSignals:
    normalized_headers = {key.lower(): value.lower() for key, value in (headers or {}).items()}
    generator = " ".join(
        content
        for tag in soup.select("meta[name='generator']")
        if isinstance((content := tag.get("content")), str)
    ).lower()

    if "x-wix-meta-site-id" in normalized_headers or "static.wixstatic.com" in lower_html:
        commerce = (
            CommercePlatform.WIX
            if "ecom.wix.com" in lower_html or "wixstores" in lower_html
            else None
        )
        return PlatformSignals("wix", "wix", commerce, 0.99)
    if (
        "cdn.shopify.com" in lower_html
        or "shopify.theme" in lower_html
        or "myshopify.com" in lower_html
    ):
        return PlatformSignals("shopify", None, CommercePlatform.SHOPIFY, 0.92)
    if "woocommerce" in lower_html or "wp-content/plugins/woocommerce" in lower_html:
        return PlatformSignals("woocommerce", "wordpress", CommercePlatform.WOOCOMMERCE, 0.98)
    if "wp-content" in lower_html or "/wp-json/" in lower_html or "wordpress" in generator:
        return PlatformSignals("wordpress", "wordpress", None, 0.95)
    if "squarespace" in lower_html or "squarespace" in generator:
        commerce = (
            CommercePlatform.SQUARESPACE
            if "sqs-add-to-cart-button" in lower_html or "product-item" in lower_html
            else None
        )
        return PlatformSignals("squarespace", "squarespace", commerce, 0.95)
    if soup.select_one("[data-wf-site]") or "webflow" in lower_html:
        return PlatformSignals("webflow", "webflow", None, 0.9)
    if "bigcommerce" in lower_html:
        return PlatformSignals("bigcommerce", None, CommercePlatform.BIGCOMMERCE, 0.9)
    return PlatformSignals(None, None, None, 0.0)
