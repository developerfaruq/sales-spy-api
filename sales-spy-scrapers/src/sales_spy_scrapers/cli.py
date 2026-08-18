import asyncio

import typer

from sales_spy_scrapers.application.crawler import BatchOutcome
from sales_spy_scrapers.config import Settings, get_settings
from sales_spy_scrapers.domain.models import DiscoveryRecord
from sales_spy_scrapers.domain.normalization import canonical_url
from sales_spy_scrapers.factory import build_crawler, build_repository
from sales_spy_scrapers.infrastructure.http_client import SafeHttpClient
from sales_spy_scrapers.logging import configure_logging
from sales_spy_scrapers.sources.common_crawl import CommonCrawlSource

app = typer.Typer(no_args_is_help=True, help="Sales-Spy scraper operations")


@app.command("check")
def check() -> None:
    """Validate configuration, database connectivity, and schema compatibility."""
    settings = get_settings()
    configure_logging(settings.log_level)
    repository = build_repository(settings)
    repository.assert_schema_compatible(settings.schema_migration)
    typer.echo("configuration and database schema are compatible")


@app.command("crawl-batch")
def crawl_batch() -> None:
    """Claim and process one batch of due websites."""
    settings = get_settings()
    configure_logging(settings.log_level)
    outcome = asyncio.run(_crawl_batch(settings))
    typer.echo(outcome)


@app.command("enqueue")
def enqueue(
    domains: list[str] = typer.Argument(..., help="Domains or URLs to enqueue"),
    source: str = typer.Option("manual", help="Discovery source identifier"),
) -> None:
    """Normalize and enqueue one or more domains for crawling."""
    settings = get_settings()
    configure_logging(settings.log_level)
    discoveries = [
        DiscoveryRecord(
            domain=domain,
            canonical_url=canonical_url(domain),
            source=source,
        )
        for domain in domains
    ]
    count = build_repository(settings).enqueue_discoveries(discoveries)
    typer.echo(f"enqueued {count} domains")


@app.command("discover-common-crawl")
def discover_common_crawl(
    pattern: str = typer.Argument(..., help="Common Crawl URL pattern, for example *.shop"),
    max_records: int = typer.Option(10_000, min=1, max=100_000),
) -> None:
    """Discover canonical domains from the configured Common Crawl index."""
    settings = get_settings()
    configure_logging(settings.log_level)
    records = asyncio.run(
        CommonCrawlSource(
            index_name=settings.common_crawl_index,
            user_agent=settings.user_agent,
            max_records=max_records,
            timeout_seconds=settings.common_crawl_deadline_seconds,
            max_response_bytes=settings.common_crawl_max_response_bytes,
        ).discover(pattern)
    )
    count = build_repository(settings).enqueue_discoveries(records)
    typer.echo(f"discovered and enqueued {count} domains")


async def _crawl_batch(settings: Settings) -> BatchOutcome:
    async with SafeHttpClient(settings) as client:
        crawler = build_crawler(settings, client)
        return await crawler.run_claimed_batch()
