import asyncio
from dataclasses import dataclass
from enum import StrEnum
from typing import Protocol
from uuid import UUID

import structlog
from pydantic import ValidationError

from sales_spy_scrapers.domain.errors import (
    ClaimLostError,
    DataConflictError,
    RobotsDeniedError,
    ScraperError,
)
from sales_spy_scrapers.domain.models import CrawlResult, CrawlTarget
from sales_spy_scrapers.domain.normalization import canonical_url
from sales_spy_scrapers.infrastructure.http_client import FetchedPage
from sales_spy_scrapers.processors.shopify import ShopifyCatalogCrawler
from sales_spy_scrapers.processors.website_parser import parse_website

log = structlog.get_logger(__name__)


@dataclass(frozen=True, slots=True)
class BatchOutcome:
    claimed: int
    completed: int
    failed: int
    blocked: int


class TargetOutcome(StrEnum):
    COMPLETED = "completed"
    FAILED = "failed"
    BLOCKED = "blocked"


class CrawlRepository(Protocol):
    def claim_due_websites(self) -> list[CrawlTarget]: ...

    def persist_result(self, target: CrawlTarget, result: CrawlResult) -> int: ...

    def mark_failed(self, website_id: int, claim_token: UUID, error: str) -> None: ...

    def mark_blocked(self, website_id: int, claim_token: UUID, reason: str) -> None: ...

    def recover_stale_claims(self) -> int: ...


class HtmlFetcher(Protocol):
    async def fetch_html(self, url: str) -> FetchedPage: ...

    async def fetch_json(self, url: str) -> FetchedPage: ...


class WebsiteCrawler:
    def __init__(
        self,
        repository: CrawlRepository,
        http_client: HtmlFetcher,
        crawl_deadline_seconds: float,
        database_concurrency: int = 4,
        target_concurrency: int = 10,
    ) -> None:
        self._repository = repository
        self._http = http_client
        self._crawl_deadline_seconds = crawl_deadline_seconds
        self._database_semaphore = asyncio.Semaphore(database_concurrency)
        self._parser_semaphore = asyncio.Semaphore(2)
        self._target_semaphore = asyncio.Semaphore(target_concurrency)

    async def crawl_target(self, target: CrawlTarget) -> TargetOutcome:
        try:
            try:
                async with self._target_semaphore, asyncio.timeout(self._crawl_deadline_seconds):
                    result, page = await self._prepare_result(target)
            except TimeoutError:
                error = f"crawl exceeded {self._crawl_deadline_seconds} second deadline"
                await asyncio.to_thread(
                    self._repository.mark_failed,
                    target.id,
                    target.claim_token,
                    error,
                )
                log.warning("website_crawl_deadline_exceeded", website_id=target.id)
                return TargetOutcome.FAILED
            async with self._database_semaphore:
                await asyncio.to_thread(self._repository.persist_result, target, result)
            log.info(
                "website_crawl_completed",
                website_id=target.id,
                domain=target.domain,
                status_code=page.status_code,
                elapsed_ms=page.elapsed_ms,
                platform=result.website.platform,
            )
            return TargetOutcome.COMPLETED
        except RobotsDeniedError as exc:
            await asyncio.to_thread(
                self._repository.mark_blocked,
                target.id,
                target.claim_token,
                str(exc),
            )
            log.info("website_crawl_blocked", website_id=target.id, domain=target.domain)
            return TargetOutcome.BLOCKED
        except ValidationError as exc:
            await asyncio.to_thread(
                self._repository.mark_blocked,
                target.id,
                target.claim_token,
                f"permanent validation error: {exc}",
            )
            return TargetOutcome.BLOCKED
        except DataConflictError as exc:
            await asyncio.to_thread(
                self._repository.mark_blocked,
                target.id,
                target.claim_token,
                f"permanent data conflict: {exc}",
            )
            return TargetOutcome.BLOCKED
        except ClaimLostError:
            log.warning("website_claim_lost", website_id=target.id, domain=target.domain)
            return TargetOutcome.FAILED
        except (ScraperError, ValueError) as exc:
            await asyncio.to_thread(
                self._repository.mark_failed,
                target.id,
                target.claim_token,
                str(exc),
            )
            log.warning(
                "website_crawl_failed",
                website_id=target.id,
                domain=target.domain,
                error=str(exc),
            )
            return TargetOutcome.FAILED
        except Exception as exc:
            await asyncio.to_thread(
                self._repository.mark_failed,
                target.id,
                target.claim_token,
                str(exc),
            )
            log.exception(
                "website_crawl_unexpected_error",
                website_id=target.id,
                domain=target.domain,
            )
            return TargetOutcome.FAILED

    async def _prepare_result(self, target: CrawlTarget) -> tuple[CrawlResult, FetchedPage]:
        url = target.canonical_url or canonical_url(target.domain)
        page = await self._http.fetch_html(url)
        async with self._parser_semaphore:
            website, store, products = await asyncio.to_thread(
                parse_website,
                target.domain,
                page.url,
                page.content.decode("utf-8", errors="replace"),
                page.status_code,
                page.headers,
                target.source,
                target.source_external_id,
            )
        products_complete = False
        if store and store.platform == "shopify":
            try:
                catalog = await ShopifyCatalogCrawler(self._http).crawl(page.url)
                products = catalog.products
                products_complete = catalog.complete
                store = store.model_copy(
                    update={
                        "product_count": len(products) if products_complete else None,
                        "platform_metadata": {
                            **store.platform_metadata,
                            "catalog_complete": products_complete,
                        },
                    }
                )
            except (ScraperError, ValueError) as exc:
                store = store.model_copy(
                    update={
                        "platform_metadata": {
                            **store.platform_metadata,
                            "catalog_error": str(exc)[:500],
                        }
                    }
                )
                log.warning(
                    "shopify_catalog_failed",
                    website_id=target.id,
                    domain=target.domain,
                    error=str(exc),
                )
        return (
            CrawlResult(
                website=website,
                store=store,
                products=products,
                products_complete=products_complete,
            ),
            page,
        )

    async def run_claimed_batch(self) -> BatchOutcome:
        recovered = await asyncio.to_thread(self._repository.recover_stale_claims)
        if recovered:
            log.warning("stale_claims_recovered", count=recovered)
        targets = await asyncio.to_thread(self._repository.claim_due_websites)
        if not targets:
            return BatchOutcome(claimed=0, completed=0, failed=0, blocked=0)
        results = await asyncio.gather(*(self.crawl_target(target) for target in targets))
        completed = results.count(TargetOutcome.COMPLETED)
        blocked = results.count(TargetOutcome.BLOCKED)
        return BatchOutcome(
            claimed=len(targets),
            completed=completed,
            failed=len(targets) - completed - blocked,
            blocked=blocked,
        )
