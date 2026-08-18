from datetime import UTC, datetime

import pytest
from pydantic import ValidationError

from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.domain.models import ProductRecord, WebsiteRecord


def test_website_record_is_normalized_and_immutable() -> None:
    record = WebsiteRecord(
        domain="HTTPS://WWW.Example.com/path",
        canonical_url="https://www.example.com/path",
        country_code="us",
        source="test",
        last_seen_at=datetime.now(UTC),
    )

    assert record.domain == "example.com"
    assert record.canonical_url == "https://example.com"
    assert record.country_code == "US"
    with pytest.raises(ValidationError):
        record.domain = "changed.example.com"  # type: ignore[misc]


def test_product_requires_external_id_or_handle() -> None:
    with pytest.raises(ValidationError, match="external_id or handle"):
        ProductRecord(title="Invalid", last_seen_at=datetime.now(UTC))


def test_product_rejects_unknown_fields() -> None:
    with pytest.raises(ValidationError, match="Extra inputs"):
        ProductRecord(
            external_id="1",
            title="Product",
            last_seen_at=datetime.now(UTC),
            unknown="value",  # type: ignore[call-arg]
        )


def test_settings_repr_redacts_connection_secrets() -> None:
    settings = Settings(
        database_url="postgresql://secret-user:secret-password@localhost:5432/sales_spy",
        redis_url="redis://:redis-secret@localhost:6379/0",
        claim_batch_size=10,
        max_concurrency=10,
    )

    rendered = repr(settings)
    assert "secret-password" not in rendered
    assert "redis-secret" not in rendered


def test_settings_reject_claim_batches_larger_than_concurrency() -> None:
    with pytest.raises(ValidationError, match="claim batch size"):
        Settings(
            database_url="postgresql://user:password@localhost:5432/sales_spy",
            redis_url="redis://localhost:6379/0",
            max_concurrency=2,
            claim_batch_size=3,
        )
