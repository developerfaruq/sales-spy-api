import asyncio

import structlog
from celery import Celery
from celery.signals import beat_init, celeryd_init
from redis import Redis

from sales_spy_scrapers.application.store_scanner import StoreScanWorker
from sales_spy_scrapers.cli import _crawl_batch
from sales_spy_scrapers.config import get_settings
from sales_spy_scrapers.factory import build_repository
from sales_spy_scrapers.infrastructure.http_client import SafeHttpClient
from sales_spy_scrapers.logging import configure_logging

log = structlog.get_logger(__name__)

settings = get_settings()
configure_logging(settings.log_level)
lease_seconds = settings.stale_claim_minutes * 60

celery_app = Celery("sales_spy_scrapers", broker=settings.redis_url.get_secret_value())
celery_app.conf.update(
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    task_default_queue="crawl",
    broker_connection_retry_on_startup=True,
    task_soft_time_limit=lease_seconds - 180,
    task_time_limit=lease_seconds - 120,
    beat_schedule={
        "crawl-due-websites-every-minute": {
            "task": "sales_spy_scrapers.crawl_due_batch",
            "schedule": 60.0,
            "options": {"expires": 55},
        },
        "process-store-scans-every-minute": {
            "task": "sales_spy_scrapers.process_store_scans",
            "schedule": 60.0,
            "options": {"expires": 55},
        },
    },
)


def assert_schema_compatible(**_: object) -> None:
    """Refuse to start against a database Laravel has not migrated yet.

    Import stays cheap so the module remains testable; this runs only when a
    worker or beat process actually boots. Raising here aborts startup instead
    of letting every query fail later.
    """
    build_repository(settings).assert_schema_compatible(settings.schema_migration)
    log.info("schema_compatibility_verified", migration=settings.schema_migration)


celeryd_init.connect(assert_schema_compatible)
beat_init.connect(assert_schema_compatible)


def crawl_due_batch() -> dict[str, int]:
    redis_client = Redis.from_url(settings.redis_url.get_secret_value())
    lock = redis_client.lock(
        "sales-spy:scraper:crawl-batch",
        timeout=lease_seconds - 60,
        blocking=False,
    )
    if not lock.acquire(blocking=False):
        return {"claimed": 0, "completed": 0, "failed": 0, "blocked": 0}
    try:
        outcome = asyncio.run(_crawl_batch(settings))
        return {
            "claimed": outcome.claimed,
            "completed": outcome.completed,
            "failed": outcome.failed,
            "blocked": outcome.blocked,
        }
    finally:
        if lock.owned():
            lock.release()
        redis_client.close()


celery_app.task(
    name="sales_spy_scrapers.crawl_due_batch",
    autoretry_for=(),
    max_retries=0,
)(crawl_due_batch)


def process_store_scans() -> dict[str, int]:
    return asyncio.run(_process_store_scans())


async def _process_store_scans() -> dict[str, int]:
    async with SafeHttpClient(settings) as client:
        outcome = await StoreScanWorker(
            build_repository(settings),
            client,
            settings.crawl_deadline_seconds,
            settings.deep_scan_page_limit,
            settings.deep_scan_product_limit,
        ).run_batch()
    return {
        "claimed": outcome.claimed,
        "completed": outcome.completed,
        "failed": outcome.failed,
    }


celery_app.task(
    name="sales_spy_scrapers.process_store_scans",
    autoretry_for=(),
    max_retries=0,
)(process_store_scans)
