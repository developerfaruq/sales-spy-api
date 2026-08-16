import json
from datetime import datetime
from typing import Any
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, field_validator

from sales_spy_scrapers.domain.enums import (
    CommercePlatform,
    ProductStatus,
    WebsiteStatus,
)
from sales_spy_scrapers.domain.normalization import (
    canonical_url,
    normalize_country_code,
    normalize_currency_code,
    normalize_domain,
)


class DomainModel(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", use_enum_values=True)


class CrawlTarget(DomainModel):
    id: int
    domain: str
    canonical_url: str | None = None
    source: str
    source_external_id: str | None = None
    crawl_attempts: int = Field(default=0, ge=0)
    claim_token: UUID
    claim_lease_expires_at: datetime

    @field_validator("domain", mode="before")
    @classmethod
    def normalize_domain_value(cls, value: str) -> str:
        return normalize_domain(value)

    @field_validator("canonical_url", mode="before")
    @classmethod
    def normalize_url_value(cls, value: str | None) -> str | None:
        return canonical_url(value) if value else None


class DiscoveryRecord(DomainModel):
    domain: str
    source: str = Field(min_length=1, max_length=64)
    source_external_id: str | None = Field(default=None, max_length=255)
    canonical_url: str = Field(max_length=2048)
    source_payload: dict[str, Any] = Field(default_factory=dict)

    @field_validator("domain", mode="before")
    @classmethod
    def normalize_domain_value(cls, value: str) -> str:
        return normalize_domain(value)

    @field_validator("canonical_url", mode="before")
    @classmethod
    def normalize_url_value(cls, value: str) -> str:
        return canonical_url(value)


class WebsiteRecord(DomainModel):
    domain: str
    canonical_url: str = Field(max_length=2048)
    source: str = Field(default="website_crawler", min_length=1, max_length=64)
    source_external_id: str | None = Field(default=None, max_length=255)
    name: str | None = Field(default=None, max_length=255)
    description: str | None = None
    website_status: WebsiteStatus = WebsiteStatus.UNKNOWN
    is_ecommerce: bool = False
    platform: str | None = Field(default=None, max_length=64)
    cms: str | None = Field(default=None, max_length=64)
    niche: str | None = Field(default=None, max_length=255)
    country_code: str | None = None
    language_code: str | None = Field(default=None, max_length=12)
    email: str | None = Field(default=None, max_length=255)
    phone: str | None = Field(default=None, max_length=64)
    contact_page_url: str | None = Field(default=None, max_length=2048)
    logo_url: str | None = Field(default=None, max_length=2048)
    favicon_url: str | None = Field(default=None, max_length=2048)
    http_status: int | None = Field(default=None, ge=100, le=599)
    technologies: list[str] = Field(default_factory=list)
    social_links: dict[str, str] = Field(default_factory=dict)
    source_payload: dict[str, Any] = Field(default_factory=dict)
    last_seen_at: datetime

    def model_post_init(self, __context: Any) -> None:
        if len(self.social_links) > 20:
            raise ValueError("website may contain at most 20 social links")
        if len(json.dumps(self.source_payload)) > 100_000:
            raise ValueError("website source payload exceeds the 100 KB limit")

    @field_validator("domain", mode="before")
    @classmethod
    def normalize_domain_value(cls, value: str) -> str:
        return normalize_domain(value)

    @field_validator("canonical_url", mode="before")
    @classmethod
    def normalize_url_value(cls, value: str) -> str:
        return canonical_url(value)

    @field_validator("country_code", mode="before")
    @classmethod
    def normalize_country(cls, value: str | None) -> str | None:
        return normalize_country_code(value)


class EcommerceStoreRecord(DomainModel):
    platform: CommercePlatform
    platform_store_id: str | None = Field(default=None, max_length=255)
    store_name: str | None = Field(default=None, max_length=255)
    category: str | None = Field(default=None, max_length=255)
    currency_code: str | None = None
    product_count: int | None = Field(default=None, ge=0)
    collection_count: int | None = Field(default=None, ge=0)
    average_price_cents: int | None = Field(default=None, ge=0)
    minimum_price_cents: int | None = Field(default=None, ge=0)
    maximum_price_cents: int | None = Field(default=None, ge=0)
    has_discounted_products: bool | None = None
    accepts_payments: bool | None = None
    has_cart: bool | None = None
    payment_methods: list[str] = Field(default_factory=list)
    shipping_countries: list[str] = Field(default_factory=list)
    platform_metadata: dict[str, Any] = Field(default_factory=dict)

    def model_post_init(self, __context: Any) -> None:
        if len(json.dumps(self.platform_metadata)) > 100_000:
            raise ValueError("store platform metadata exceeds the 100 KB limit")

    @field_validator("currency_code", mode="before")
    @classmethod
    def normalize_currency(cls, value: str | None) -> str | None:
        return normalize_currency_code(value)


class ProductRecord(DomainModel):
    external_id: str | None = Field(default=None, max_length=255)
    handle: str | None = Field(default=None, max_length=255)
    title: str = Field(min_length=1, max_length=255)
    description: str | None = None
    vendor: str | None = Field(default=None, max_length=255)
    product_type: str | None = Field(default=None, max_length=255)
    status: ProductStatus = ProductStatus.ACTIVE
    currency_code: str | None = None
    price_cents: int | None = Field(default=None, ge=0)
    compare_at_price_cents: int | None = Field(default=None, ge=0)
    minimum_variant_price_cents: int | None = Field(default=None, ge=0)
    maximum_variant_price_cents: int | None = Field(default=None, ge=0)
    variant_count: int = Field(default=0, ge=0)
    is_available: bool = True
    inventory_quantity: int | None = None
    product_url: str | None = Field(default=None, max_length=2048)
    primary_image_url: str | None = Field(default=None, max_length=2048)
    tags: list[str] = Field(default_factory=list)
    images: list[str] = Field(default_factory=list)
    variants: list[dict[str, Any]] = Field(default_factory=list)
    source_payload: dict[str, Any] = Field(default_factory=dict)
    published_at: datetime | None = None
    source_created_at: datetime | None = None
    source_updated_at: datetime | None = None
    last_seen_at: datetime

    def model_post_init(self, __context: Any) -> None:
        if len(self.variants) > 100 or len(self.images) > 100:
            raise ValueError("product contains too many variants or images")
        if len(json.dumps(self.variants)) + len(json.dumps(self.source_payload)) > 500_000:
            raise ValueError("product structured payload exceeds the 500 KB limit")
        if self.external_id is None and self.handle is None:
            raise ValueError("product requires external_id or handle")

    @field_validator("currency_code", mode="before")
    @classmethod
    def normalize_currency(cls, value: str | None) -> str | None:
        return normalize_currency_code(value)

    @field_validator("handle", "external_id")
    @classmethod
    def normalize_identity(cls, value: str | None) -> str | None:
        return value.strip() if value and value.strip() else None


class CrawlResult(DomainModel):
    website: WebsiteRecord
    store: EcommerceStoreRecord | None = None
    products: list[ProductRecord] = Field(default_factory=list)
    products_complete: bool = False


class StoreScanTarget(DomainModel):
    id: str
    user_id: int
    ecommerce_store_id: int
    domain: str
    canonical_url: str
    claim_token: UUID
    claim_lease_expires_at: datetime

    @field_validator("domain", mode="before")
    @classmethod
    def normalize_domain_value(cls, value: str) -> str:
        return normalize_domain(value)

    @field_validator("canonical_url", mode="before")
    @classmethod
    def normalize_url_value(cls, value: str) -> str:
        return canonical_url(value)
