from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from uuid import UUID

import pytest

from sales_spy_scrapers.application.crawler import TargetOutcome, WebsiteCrawler
from sales_spy_scrapers.domain.errors import FetchError, RobotsDeniedError
from sales_spy_scrapers.domain.models import CrawlResult, CrawlTarget
from sales_spy_scrapers.infrastructure.http_client import FetchedPage


@dataclass
class FakeRepository:
    targets: list[CrawlTarget] = field(default_factory=list)
    persisted: list[CrawlResult] = field(default_factory=list)
    failed: list[tuple[int, UUID, str]] = field(default_factory=list)
    blocked: list[tuple[int, UUID, str]] = field(default_factory=list)
    recovered: int = 0

    def claim_due_websites(self) -> list[CrawlTarget]:
        return self.targets

    def persist_result(self, target: CrawlTarget, result: CrawlResult) -> int:
        self.persisted.append(result)
        return 1

    def mark_failed(self, website_id: int, claim_token: UUID, error: str) -> None:
        self.failed.append((website_id, claim_token, error))

    def mark_blocked(self, website_id: int, claim_token: UUID, reason: str) -> None:
        self.blocked.append((website_id, claim_token, reason))

    def recover_stale_claims(self) -> int:
        return self.recovered


class FakeHttpClient:
    def __init__(self, error: Exception | None = None) -> None:
        self.error = error

    async def fetch_html(self, url: str) -> FetchedPage:
        if self.error:
            raise self.error
        return FetchedPage(
            url,
            200,
            {"content-type": "text/html"},
            b"<html><head><title>Example</title></head></html>",
            5,
        )

    async def fetch_json(self, url: str) -> FetchedPage:
        return FetchedPage(url, 200, {"content-type": "application/json"}, b'{"products": []}', 1)


class SlowHttpClient(FakeHttpClient):
    async def fetch_html(self, url: str) -> FetchedPage:
        await __import__("asyncio").sleep(0.05)
        return await super().fetch_html(url)


def target(identifier: int = 1) -> CrawlTarget:
    return CrawlTarget(
        id=identifier,
        domain=f"example{identifier}.com",
        source="test",
        source_external_id=f"source-{identifier}",
        claim_token=UUID(int=identifier),
        claim_lease_expires_at=datetime.now(UTC) + timedelta(minutes=30),
    )


@pytest.mark.asyncio
async def test_successful_target_is_persisted() -> None:
    repository = FakeRepository()
    crawler = WebsiteCrawler(repository, FakeHttpClient(), 30)

    outcome = await crawler.crawl_target(target())

    assert outcome is TargetOutcome.COMPLETED
    assert repository.persisted[0].website.source_external_id == "source-1"
    assert repository.failed == []


@pytest.mark.asyncio
async def test_robots_denial_marks_target_blocked() -> None:
    repository = FakeRepository()
    crawler = WebsiteCrawler(repository, FakeHttpClient(RobotsDeniedError("denied")), 30)

    outcome = await crawler.crawl_target(target())

    assert outcome is TargetOutcome.BLOCKED
    assert repository.blocked == [(1, UUID(int=1), "denied")]


@pytest.mark.asyncio
async def test_fetch_error_marks_target_failed() -> None:
    repository = FakeRepository()
    crawler = WebsiteCrawler(repository, FakeHttpClient(FetchError("timeout")), 30)

    outcome = await crawler.crawl_target(target())

    assert outcome is TargetOutcome.FAILED
    assert repository.failed == [(1, UUID(int=1), "timeout")]


@pytest.mark.asyncio
async def test_batch_counts_completed_failed_and_blocked() -> None:
    repository = FakeRepository(targets=[target(1), target(2), target(3)])
    crawler = WebsiteCrawler(repository, FakeHttpClient(), 30)
    outcomes = iter([TargetOutcome.COMPLETED, TargetOutcome.FAILED, TargetOutcome.BLOCKED])

    async def fake_crawl(_: CrawlTarget) -> TargetOutcome:
        return next(outcomes)

    monkeypatch = pytest.MonkeyPatch()
    monkeypatch.setattr(crawler, "crawl_target", fake_crawl)
    outcome = await crawler.run_claimed_batch()
    monkeypatch.undo()

    assert outcome.claimed == 3
    assert outcome.completed == 1
    assert outcome.failed == 1
    assert outcome.blocked == 1


@pytest.mark.asyncio
async def test_deadline_marks_target_failed_with_claim_token() -> None:
    repository = FakeRepository()
    crawler = WebsiteCrawler(repository, SlowHttpClient(), 0.01)

    outcome = await crawler.crawl_target(target())

    assert outcome is TargetOutcome.FAILED
    assert repository.failed[0][0:2] == (1, UUID(int=1))
    assert "deadline" in repository.failed[0][2]
