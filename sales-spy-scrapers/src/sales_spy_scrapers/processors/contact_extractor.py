import re
from collections.abc import Iterable
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

_EMAIL = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.IGNORECASE)
_PHONE = re.compile(r"(?:\+?\d[\d\s().-]{7,}\d)")
_SOCIAL_HOSTS = {
    "facebook.com": "facebook",
    "instagram.com": "instagram",
    "linkedin.com": "linkedin",
    "tiktok.com": "tiktok",
    "twitter.com": "twitter",
    "x.com": "twitter",
    "youtube.com": "youtube",
    "pinterest.com": "pinterest",
}


def extract_contacts(
    html: str,
    page_url: str,
) -> tuple[list[str], list[str], dict[str, str], str | None]:
    soup = BeautifulSoup(html, "lxml")
    return extract_contacts_from_soup(soup, page_url)


def extract_contacts_from_soup(
    soup: BeautifulSoup,
    page_url: str,
) -> tuple[list[str], list[str], dict[str, str], str | None]:
    text = soup.get_text(" ", strip=True)
    emails = sorted(
        set(
            _EMAIL.findall(text) + [href[7:] for href in _hrefs(soup, "mailto:") if "@" in href[7:]]
        )
    )
    phones = sorted(set(_PHONE.findall(text) + [href[4:] for href in _hrefs(soup, "tel:")]))
    social_links: dict[str, str] = {}
    for anchor in soup.select("a[href]"):
        absolute = _safe_http_url(page_url, _string_attribute(anchor.get("href")))
        if absolute is None:
            continue
        host = (urlparse(absolute).hostname or "").lower().removeprefix("www.")
        for social_host, network in _SOCIAL_HOSTS.items():
            if host == social_host or host.endswith(f".{social_host}"):
                social_links.setdefault(network, absolute)
                break

    contact_page_url = _find_contact_page(soup, page_url)
    return emails[:10], phones[:10], social_links, contact_page_url


def _hrefs(soup: BeautifulSoup, prefix: str) -> Iterable[str]:
    for anchor in soup.select("a[href]"):
        href = _string_attribute(anchor.get("href")).strip()
        if href.lower().startswith(prefix):
            yield href


def _find_contact_page(soup: BeautifulSoup, page_url: str) -> str | None:
    for anchor in soup.select("a[href]"):
        label = anchor.get_text(" ", strip=True).lower()
        href = _string_attribute(anchor.get("href")).strip()
        if "contact" in label or "contact" in href.lower():
            return _safe_http_url(page_url, href)
    return None


def _string_attribute(value: object) -> str:
    return value if isinstance(value, str) else ""


def _safe_http_url(base_url: str, value: str) -> str | None:
    absolute = urljoin(base_url, value)
    return absolute if urlparse(absolute).scheme in {"http", "https"} else None
