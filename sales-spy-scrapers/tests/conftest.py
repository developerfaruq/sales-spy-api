import pytest

from sales_spy_scrapers.config import Settings


@pytest.fixture
def settings() -> Settings:
    return Settings(
        database_url="postgresql://user:password@localhost:5432/sales_spy",
        redis_url="redis://localhost:6379/0",
        user_agent="SalesSpyBot-Test/1.0",
        max_concurrency=4,
        claim_batch_size=4,
        per_host_concurrency=2,
        requests_per_second=100,
        max_attempts=3,
        max_response_bytes=4096,
        robots_cache_seconds=60,
    )
