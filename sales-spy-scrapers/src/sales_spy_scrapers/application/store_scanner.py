import asyncio
from dataclasses import dataclass
from typing import Protocol

import structlog

from sales_spy_scrapers.domain.errors import ClaimLostError
from sales_spy_scrapers.domain.models import ProductRecord, StoreScanTarget
from sales_spy_scrapers.processors.shopify import JsonFetcher, ShopifyCatalogCrawler

log = structlog.get_logger(__name__)


class ScanRepository(Protocol):
    def claim_store_scans(self) -> list[StoreScanTarget]: ...

    def recover_stale_store_scans(self) -> int: ...

    def complete_store_scan(
        self, target: StoreScanTarget, products: list[ProductRecord]
    ) -> None: ...

    def fail_store_scan(self, target: StoreScanTarget, error: str) -> None: ...


@dataclass(frozen=True, slots=True)
class ScanBatchOutcome:
    claimed: int
    completed: int
    failed: int


class StoreScanWorker:
    def __init__(
        self,
        repository: ScanRepository,
        http_client: JsonFetcher,
        deadline_seconds: int,
        page_limit: int,
        product_limit: int,
    ) -> None:
        self._repository = repository
        self._http = http_client
        self._deadline_seconds = deadline_seconds
        self._page_limit = page_limit
        self._product_limit = product_limit

    async def run_batch(self) -> ScanBatchOutcome:
        recovered = await asyncio.to_thread(self._repository.recover_stale_store_scans)
        if recovered:
            log.warning("stale_scan_claims_recovered", count=recovered)
        targets = await asyncio.to_thread(self._repository.claim_store_scans)
        if not targets:
            return ScanBatchOutcome(claimed=0, completed=0, failed=0)
        results = await asyncio.gather(*(self._scan(target) for target in targets))
        completed = sum(results)
        return ScanBatchOutcome(
            claimed=len(targets),
            completed=completed,
            failed=len(targets) - completed,
        )

    async def _scan(self, target: StoreScanTarget) -> bool:
        try:
            async with asyncio.timeout(self._deadline_seconds):
                catalog = await ShopifyCatalogCrawler(
                    self._http,
                    page_limit=self._page_limit,
                    product_limit=self._product_limit,
                    aggregate_byte_limit=100_000_000,
                ).crawl(target.canonical_url)
            if not catalog.complete:
                raise ValueError("deep scan reached a configured limit before catalog completion")
            await asyncio.to_thread(
                self._repository.complete_store_scan,
                target,
                catalog.products,
            )
            log.info("store_scan_completed", scan_id=target.id, domain=target.domain)
            return True
        except ClaimLostError:
            log.warning("store_scan_claim_lost", scan_id=target.id)
            return False
        except Exception as exc:
            await asyncio.to_thread(self._repository.fail_store_scan, target, str(exc))
            log.warning("store_scan_failed", scan_id=target.id, error=str(exc))
            return False
