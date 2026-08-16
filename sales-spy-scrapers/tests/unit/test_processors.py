from sales_spy_scrapers.domain.enums import CommercePlatform
from sales_spy_scrapers.processors.contact_extractor import extract_contacts
from sales_spy_scrapers.processors.platform_detector import detect_platform
from sales_spy_scrapers.processors.website_parser import parse_website


def test_contact_extractor_deduplicates_contacts_and_finds_socials() -> None:
    html = """
    <html><body>
      Contact sales@example.com or <a href="mailto:sales@example.com">Email</a>
      <a href="tel:+1 212 555 0123">Call</a>
      <a href="https://www.instagram.com/example">Instagram</a>
      <a href="/contact-us">Contact us</a>
    </body></html>
    """

    emails, phones, socials, contact = extract_contacts(html, "https://example.com")

    assert emails == ["sales@example.com"]
    assert "+1 212 555 0123" in phones
    assert socials == {"instagram": "https://www.instagram.com/example"}
    assert contact == "https://example.com/contact-us"


def test_platform_detector_prioritizes_woocommerce_over_wordpress() -> None:
    result = detect_platform('<link href="/wp-content/plugins/woocommerce/style.css">')

    assert result.platform == "woocommerce"
    assert result.cms == "wordpress"
    assert result.commerce is CommercePlatform.WOOCOMMERCE


def test_wix_header_is_high_confidence_commerce_signal() -> None:
    result = detect_platform(
        '<html><script src="https://ecom.wix.com/store.js"></script></html>',
        {"X-Wix-Meta-Site-Id": "abc"},
    )

    assert result.commerce is CommercePlatform.WIX
    assert result.confidence == 0.99


def test_generic_wix_site_is_not_assumed_to_be_a_store() -> None:
    result = detect_platform("<html></html>", {"X-Wix-Meta-Site-Id": "abc"})

    assert result.platform == "wix"
    assert result.commerce is None


def test_website_parser_produces_store_and_provenance() -> None:
    html = """
    <html><head><title>Example Shop</title>
    <meta name="description" content="A test store">
    <script src="https://cdn.shopify.com/theme.js"></script></head>
    <body><a href="mailto:owner@example.com">Contact</a></body></html>
    """

    website, store, products = parse_website(
        "example.com",
        "https://example.com",
        html,
        200,
        {},
        source="common_crawl",
        source_external_id="record-1",
    )

    assert website.name == "Example Shop"
    assert website.description == "A test store"
    assert website.email == "owner@example.com"
    assert website.source == "common_crawl"
    assert website.source_external_id == "record-1"
    assert website.is_ecommerce is True
    assert store is not None
    assert store.platform == "shopify"
    assert products == []
