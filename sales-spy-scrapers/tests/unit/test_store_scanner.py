import json
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from uuid import UUID

import pytest

from sales_spy_scrapers.application.store_scanner import StoreScanWorker
from sales_spy_scrapers.domain.models import ProductRecord, StoreScanTarget
from sales_spy_scrapers.infrastructure.http_client import FetchedPage


@dataclass
class FakeScanRepository:
    targets: list[StoreScanTarget] = field(default_factory=list)
    completed: list[str] = field(default_factory=list)
    failed: list[tuple[str, str]] = field(default_factory=list)

    def claim_store_scans(self) -> list[StoreScanTarget]:
        return self.targets

    def recover_stale_store_scans(self) -> int:
        return 0

    def complete_store_scan(self, target: StoreScanTarget, products: list[ProductRecord]) -> None:
        self.completed.append(target.id)

    def fail_store_scan(self, target: StoreScanTarget, error: str) -> None:
        self.failed.append((target.id, error))


class FakeJsonClient:
    def __init__(self, product_count: int) -> None:
        self.product_count = product_count

    async def fetch_json(self, url: str) -> FetchedPage:
        payload = {
            "products": [
                {"id": index, "title": f"Product {index}", "variants": []}
                for index in range(self.product_count)
            ]
        }
        return FetchedPage(
            url,
            200,
            {"content-type": "application/json"},
            json.dumps(payload).encode(),
            1,
        )


def target() -> StoreScanTarget:
    return StoreScanTarget(
        id="01JSCAN00000000000000000000",
        user_id=1,
        ecommerce_store_id=1,
        domain="shop.example.com",
        canonical_url="https://shop.example.com",
        claim_token=UUID(int=1),
        claim_lease_expires_at=datetime.now(UTC) + timedelta(minutes=30),
    )


@pytest.mark.asyncio
async def test_successful_scan_completes_with_products() -> None:
    repository = FakeScanRepository(targets=[target()])
    worker = StoreScanWorker(repository, FakeJsonClient(1), 30, 10, 100)

    outcome = await worker.run_batch()

    assert outcome.claimed == 1
    assert outcome.completed == 1
    assert outcome.failed == 0
    assert repository.completed == [target().id]


@pytest.mark.asyncio
async def test_incomplete_scan_is_failed() -> None:
    repository = FakeScanRepository(targets=[target()])
    worker = StoreScanWorker(repository, FakeJsonClient(250), 30, 1, 1000)

    outcome = await worker.run_batch()

    assert outcome.failed == 1
    assert repository.failed[0][0] == target().id
