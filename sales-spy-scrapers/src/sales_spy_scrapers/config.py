from functools import lru_cache

from pydantic import (
    Field,
    PostgresDsn,
    RedisDsn,
    SecretStr,
    TypeAdapter,
    field_validator,
    model_validator,
)
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_prefix="SCRAPER_",
        extra="ignore",
        frozen=True,
        hide_input_in_errors=True,
    )

    database_url: SecretStr
    redis_url: SecretStr
    user_agent: str = "SalesSpyBot/1.0"
    log_level: str = "INFO"
    max_concurrency: int = Field(default=10, ge=1, le=20)
    per_host_concurrency: int = Field(default=1, ge=1, le=10)
    requests_per_second: float = Field(default=1.0, gt=0, le=100)
    connect_timeout_seconds: float = Field(default=5.0, gt=0, le=60)
    read_timeout_seconds: float = Field(default=15.0, gt=0, le=120)
    max_response_bytes: int = Field(default=5_242_880, ge=1024, le=10_485_760)
    max_redirects: int = Field(default=5, ge=0, le=20)
    max_attempts: int = Field(default=3, ge=1, le=10)
    claim_batch_size: int = Field(default=10, ge=1, le=20)
    crawl_max_attempts: int = Field(default=5, ge=1, le=50)
    crawl_recovery_window_days: int = Field(default=7, ge=1, le=90)
    success_recrawl_hours: int = Field(default=168, ge=1, le=8760)
    failed_retry_minutes: int = Field(default=60, ge=1, le=10080)
    stale_claim_minutes: int = Field(default=30, ge=10, le=1440)
    crawl_deadline_seconds: int = Field(default=600, ge=30, le=3600)
    database_connect_timeout_seconds: int = Field(default=5, ge=1, le=30)
    database_statement_timeout_seconds: int = Field(default=30, ge=1, le=300)
    database_concurrency: int = Field(default=4, ge=1, le=20)
    database_pool_min_size: int = Field(default=1, ge=1, le=20)
    database_pool_max_size: int = Field(default=8, ge=2, le=50)
    robots_cache_seconds: int = Field(default=3600, ge=60, le=86400)
    schema_migration: str = "2026_08_07_110000_add_lead_query_indexes"
    scan_claim_batch_size: int = Field(default=2, ge=1, le=10)
    scan_max_attempts: int = Field(default=3, ge=1, le=10)
    scan_recovery_window_days: int = Field(default=7, ge=1, le=90)
    deep_scan_page_limit: int = Field(default=100, ge=1, le=100)
    deep_scan_product_limit: int = Field(default=25_000, ge=1, le=25_000)
    product_upsert_batch_size: int = Field(default=500, ge=1, le=5_000)
    common_crawl_index: str = "CC-MAIN-2026-30"
    common_crawl_deadline_seconds: int = Field(default=120, ge=10, le=600)
    common_crawl_max_response_bytes: int = Field(default=20_000_000, ge=1024, le=100_000_000)

    @field_validator("database_url", mode="before")
    @classmethod
    def validate_database_url(cls, value: object) -> SecretStr:
        return SecretStr(str(TypeAdapter(PostgresDsn).validate_python(value)))

    @field_validator("redis_url", mode="before")
    @classmethod
    def validate_redis_url(cls, value: object) -> SecretStr:
        return SecretStr(str(TypeAdapter(RedisDsn).validate_python(value)))

    @field_validator("log_level")
    @classmethod
    def normalize_log_level(cls, value: str) -> str:
        normalized = value.upper()
        if normalized not in {"DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"}:
            raise ValueError("log_level must be a standard Python log level")
        return normalized

    @field_validator("user_agent")
    @classmethod
    def require_identifiable_user_agent(cls, value: str) -> str:
        if not value.strip() or value.lower().startswith(("mozilla/", "curl/")):
            raise ValueError("user_agent must identify the Sales-Spy crawler")
        return value.strip()

    @model_validator(mode="after")
    def validate_lease_outlives_task(self) -> "Settings":
        if self.stale_claim_minutes * 60 <= self.crawl_deadline_seconds + 300:
            raise ValueError(
                "stale claim lease must exceed crawl deadline by more than 300 seconds"
            )
        if self.claim_batch_size > self.max_concurrency:
            raise ValueError("claim batch size cannot exceed target concurrency")
        if self.database_pool_max_size < self.database_pool_min_size:
            raise ValueError("database pool max size cannot be below its min size")
        # Some paths (SKIP LOCKED overlap checks) hold two connections at once,
        # and each crawl thread needs one of its own.
        if self.database_pool_max_size < self.database_concurrency + 1:
            raise ValueError("database pool must exceed database concurrency by at least one")
        return self


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
