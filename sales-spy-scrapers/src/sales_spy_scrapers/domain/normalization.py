import ipaddress
import re
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation
from urllib.parse import urlsplit, urlunsplit

from sales_spy_scrapers.domain.errors import InvalidDomainError

_DOMAIN_LABEL = re.compile(r"^(?!-)[a-z0-9-]{1,63}(?<!-)$")


def normalize_domain(value: str) -> str:
    candidate = value.strip().lower()
    parsed = urlsplit(candidate if "://" in candidate else f"https://{candidate}")
    host = (parsed.hostname or "").rstrip(".")

    if host.startswith("www."):
        host = host[4:]

    try:
        host = host.encode("idna").decode("ascii")
    except UnicodeError as exc:
        raise InvalidDomainError("domain contains invalid international characters") from exc

    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise InvalidDomainError("IP addresses are not valid lead domains")

    labels = host.split(".")
    if len(labels) < 2 or len(host) > 253 or any(not _DOMAIN_LABEL.fullmatch(x) for x in labels):
        raise InvalidDomainError("a valid registrable domain is required")

    return host


def canonical_url(value: str, *, default_scheme: str = "https") -> str:
    domain = normalize_domain(value)
    parsed = urlsplit(value if "://" in value else f"{default_scheme}://{value}")
    scheme = parsed.scheme.lower() if parsed.scheme in {"http", "https"} else default_scheme
    return urlunsplit((scheme, domain, "", "", ""))


def normalize_country_code(value: str | None) -> str | None:
    if value is None or not value.strip():
        return None
    code = value.strip().upper()
    if len(code) != 2 or not code.isalpha():
        raise ValueError("country code must be ISO 3166-1 alpha-2")
    return code


def normalize_currency_code(value: str | None) -> str | None:
    if value is None or not value.strip():
        return None
    code = value.strip().upper()
    if len(code) != 3 or not code.isalpha():
        raise ValueError("currency code must be ISO 4217")
    return code


def money_to_cents(value: str | int | float | Decimal | None) -> int | None:
    if value is None or value == "":
        return None
    try:
        amount = Decimal(str(value))
    except InvalidOperation as exc:
        raise ValueError("money value is invalid") from exc
    if amount < 0:
        raise ValueError("money value cannot be negative")
    return int((amount * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))
