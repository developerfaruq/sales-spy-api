from decimal import Decimal

import pytest

from sales_spy_scrapers.domain.errors import InvalidDomainError
from sales_spy_scrapers.domain.normalization import (
    canonical_url,
    money_to_cents,
    normalize_country_code,
    normalize_currency_code,
    normalize_domain,
)


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("HTTPS://WWW.Example.COM/catalog?q=1", "example.com"),
        ("example.com.", "example.com"),
        ("https://shop.example.com:443/path", "shop.example.com"),
        ("münich.example", "xn--mnich-kva.example"),
    ],
)
def test_normalize_domain(value: str, expected: str) -> None:
    assert normalize_domain(value) == expected


@pytest.mark.parametrize(
    "value",
    ["not-a-domain", "localhost", "127.0.0.1", "https://[::1]", "-bad.example.com"],
)
def test_invalid_domains_are_rejected(value: str) -> None:
    with pytest.raises(InvalidDomainError):
        normalize_domain(value)


def test_canonical_url_removes_path_query_and_www() -> None:
    assert canonical_url("http://www.Example.com/path?q=1") == "http://example.com"


@pytest.mark.parametrize(
    ("value", "expected"),
    [("19.99", 1999), (19.995, 2000), (Decimal("0.01"), 1), (None, None)],
)
def test_money_to_cents(value: object, expected: int | None) -> None:
    assert money_to_cents(value) == expected  # type: ignore[arg-type]


def test_negative_money_is_rejected() -> None:
    with pytest.raises(ValueError, match="cannot be negative"):
        money_to_cents("-1.00")


def test_country_and_currency_codes_are_normalized() -> None:
    assert normalize_country_code(" us ") == "US"
    assert normalize_currency_code(" usd ") == "USD"
