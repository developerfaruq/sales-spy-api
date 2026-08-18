from sales_spy_scrapers.application.crawler import HtmlFetcher, WebsiteCrawler
from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.infrastructure.repository import PostgresRepository


def build_repository(settings: Settings) -> PostgresRepository:
    return PostgresRepository(settings)


def build_crawler(
    settings: Settings,
    http_client: HtmlFetcher,
) -> WebsiteCrawler:
    return WebsiteCrawler(
        build_repository(settings),
        http_client,
        settings.crawl_deadline_seconds,
        settings.database_concurrency,
        settings.max_concurrency,
    )
