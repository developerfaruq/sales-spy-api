# Sales-Spy Scrapers

Python 3.12 discovery and commerce ingestion workers for Sales-Spy. The package writes directly to the Laravel-managed PostgreSQL tables `websites`, `ecommerce_stores`, and `store_products`.

## Safety Properties

- Canonical lowercase domain identity shared with Laravel.
- PostgreSQL `FOR UPDATE SKIP LOCKED` claims with fenced UUID leases.
- Idempotent website, store, and product upserts.
- Robots policy enforcement and identifiable user agent.
- DNS and redirect SSRF protection against private, loopback, link-local, and reserved targets.
- Bounded redirects, response sizes, concurrency, per-host request rate, retries, and Shopify pagination.
- UTC timestamps, integer money, JSONB provenance, structured JSON logs.
- Strict Pydantic records, Ruff linting, strict MyPy, and an 85% unit coverage gate.

## Setup

```bash
python3.12 -m venv .venv
.venv/bin/pip install -e '.[dev]'
cp .env.example .env
```

Use a dedicated PostgreSQL role for scraper deployments. It should have `SELECT`, `INSERT`, and `UPDATE` on the three ingestion tables and `SELECT` on `migrations`; it should not own the schema or receive Laravel user/payment permissions.

Required environment variables:

```text
SCRAPER_DATABASE_URL
SCRAPER_REDIS_URL
```

Review `.env.example` for all bounded runtime settings.

Production containers install the fully pinned `requirements.lock` before installing the local package with `--no-deps`. Update and review the lock whenever dependency bounds change.

## Commands

Validate configuration and Laravel schema compatibility:

```bash
.venv/bin/sales-spy-scraper check
```

Normalize and enqueue domains:

```bash
.venv/bin/sales-spy-scraper enqueue example.com https://www.example.org/path --source manual
```

Discover from a bounded Common Crawl index query:

```bash
.venv/bin/sales-spy-scraper discover-common-crawl '*.shop' --max-records 10000
```

Process one claimed batch without Celery:

```bash
.venv/bin/sales-spy-scraper crawl-batch
```

Run a worker:

```bash
.venv/bin/celery -A sales_spy_scrapers.tasks:celery_app worker \
  --loglevel=INFO --queues=crawl --concurrency=1
```

Run one beat scheduler instance:

```bash
.venv/bin/celery -A sales_spy_scrapers.tasks:celery_app beat --loglevel=INFO
```

Only one beat instance should run per environment. Redis enforces one active crawl-batch task across workers, while fenced database claims prevent stale workers from writing after lease replacement.

## Tests

Core gates:

```bash
.venv/bin/ruff check src tests
.venv/bin/ruff format --check src tests
.venv/bin/mypy src tests
.venv/bin/pytest -m 'not integration'
```

Repository integration tests require a disposable or local PostgreSQL database with the Laravel migrations applied:

```bash
SCRAPER_TEST_DATABASE_URL='postgresql://...' \
  .venv/bin/pytest --no-cov -m integration tests/integration
```

Integration tests target only their generated website IDs and remove all generated records in `finally` blocks.

## Data Flow

```text
Common Crawl/manual sources
    -> canonical DiscoveryRecord
    -> websites (pending)
    -> SKIP LOCKED claim (crawling)
    -> robots/DNS/rate-limit guarded HTTP
    -> platform and contact processors
    -> optional bounded Shopify catalog crawl
    -> atomic PostgreSQL upsert (completed)
```

Expected failures are recorded as `failed` with a retry time. Robots denials and permanent validation/data conflicts are recorded as `blocked` with no automatic retry. Each claim has a UUID fence and expiry. Recovery clears expired fences, and every success/failure write must present the current token, so stale workers cannot overwrite replacement work.

## Current Scope

Implemented:

- Manual and Common Crawl discovery input.
- General website fetching and extraction.
- Wix, WordPress, Squarespace, Webflow, Shopify, WooCommerce, and BigCommerce signals.
- Contact, phone, social, and contact-page extraction.
- Shopify public catalog pagination and product normalization.
- PostgreSQL claim/upsert/state transitions.
- Celery worker and beat entry points.

Intentionally not fabricated:

- Authenticated WooCommerce product APIs without store credentials.
- Wix catalog APIs without site/API authorization.
- Browser rendering for JavaScript-only sites.
- Proxy rotation or CAPTCHA bypass.

These require separate reviewed adapters and must retain the same safety and testing gates.
