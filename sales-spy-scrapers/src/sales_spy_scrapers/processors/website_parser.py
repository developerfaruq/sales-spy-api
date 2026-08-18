from datetime import UTC, datetime

from bs4 import BeautifulSoup

from sales_spy_scrapers.domain.models import EcommerceStoreRecord, ProductRecord, WebsiteRecord
from sales_spy_scrapers.processors.contact_extractor import extract_contacts_from_soup
from sales_spy_scrapers.processors.platform_detector import detect_platform_from_soup


def parse_website(
    domain: str,
    page_url: str,
    html: str,
    status_code: int,
    headers: dict[str, str],
    source: str = "website_crawler",
    source_external_id: str | None = None,
) -> tuple[WebsiteRecord, EcommerceStoreRecord | None, list[ProductRecord]]:
    soup = BeautifulSoup(html, "lxml")
    lower_html = html.lower()
    signals = detect_platform_from_soup(soup, lower_html, headers)
    emails, phones, socials, contact_url = extract_contacts_from_soup(soup, page_url)
    title = soup.title.get_text(" ", strip=True) if soup.title else None
    description_tag = soup.select_one("meta[name='description']")
    description_value = description_tag.get("content") if description_tag else None
    description = description_value.strip() if isinstance(description_value, str) else None
    is_ecommerce = signals.commerce is not None
    website = WebsiteRecord(
        domain=domain,
        canonical_url=page_url,
        source=source,
        source_external_id=source_external_id,
        name=title[:255] if title else None,
        description=description[:10_000] if description else None,
        website_status="active" if 200 <= status_code < 400 else "unreachable",
        is_ecommerce=is_ecommerce,
        platform=signals.platform,
        cms=signals.cms,
        email=emails[0][:255] if emails else None,
        phone=phones[0][:64] if phones else None,
        contact_page_url=contact_url,
        social_links=socials,
        technologies=[signals.platform] if signals.platform else [],
        source_payload={"platform_confidence": signals.confidence},
        http_status=status_code,
        last_seen_at=datetime.now(UTC),
    )
    store = None
    if signals.commerce:
        store = EcommerceStoreRecord(
            platform=signals.commerce,
            store_name=title[:255] if title else None,
            platform_metadata={"confidence": signals.confidence},
        )
    return website, store, []
