import os
from collections.abc import Iterator
from datetime import UTC, datetime

import pytest

from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.domain.errors import ClaimLostError
from sales_spy_scrapers.domain.models import (
    CrawlResult,
    DiscoveryRecord,
    EcommerceStoreRecord,
    ProductRecord,
    WebsiteRecord,
)
from sales_spy_scrapers.infrastructure.repository import PostgresRepository

pytestmark = pytest.mark.integration


@pytest.fixture
def repository() -> Iterator[PostgresRepository]:
    database_url = os.getenv("SCRAPER_TEST_DATABASE_URL")
    if not database_url:
        pytest.skip("SCRAPER_TEST_DATABASE_URL is required for repository integration tests")
    instance = PostgresRepository(
        Settings(
            database_url=database_url,
            redis_url="redis://localhost:6379/0",
            user_agent="SalesSpyBot-Test/1.0",
            claim_batch_size=10,
        )
    )
    try:
        yield instance
    finally:
        # The repository now owns a connection pool; leaving it open would leak
        # connections across the suite and warn on garbage collection.
        instance.close()


def test_repository_claims_and_idempotently_persists_full_storefront(
    repository: PostgresRepository,
) -> None:
    domain = "scraper-integration.example.com"
    repository.assert_schema_compatible("2026_08_06_140000_allow_signed_product_inventory")
    try:
        assert (
            repository.enqueue_discoveries(
                [
                    DiscoveryRecord(
                        domain=domain,
                        canonical_url=f"https://{domain}",
                        source="integration_test",
                        source_external_id="integration-1",
                        source_payload={"batch": 1},
                    )
                ]
            )
            == 1
        )

        with repository.connection() as connection:
            seeded = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert seeded is not None
        targets = repository.claim_due_websites([int(seeded["id"])])
        target = next(item for item in targets if item.domain == domain)
        assert target.source == "integration_test"

        result = _result(domain, "Product version 1", external_id=None)
        website_id = repository.persist_result(target, result)
        with repository.connection() as connection:
            connection.execute(
                "UPDATE websites SET last_crawl_error = 'old error' WHERE id = %s",
                (website_id,),
            )
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'pending',
                    next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website_id,),
            )
            connection.commit()
        second_target = repository.claim_due_websites([website_id])[0]
        second_id = repository.persist_result(
            second_target,
            _result(domain, "Product version 2", external_id="product-1"),
        )

        assert second_id == website_id
        with repository.connection() as connection:
            website = connection.execute(
                """
                SELECT crawl_status, technologies, source_payload, last_crawl_error,
                       pg_typeof(last_seen_at)::text AS timestamp_type
                FROM websites WHERE id = %s
                """,
                (website_id,),
            ).fetchone()
            product = connection.execute(
                """
                SELECT p.title, p.price_cents, p.status, p.is_available,
                       p.last_seen_at, p.external_id, p.handle
                FROM store_products p
                JOIN ecommerce_stores s ON s.id = p.ecommerce_store_id
                WHERE s.website_id = %s AND p.external_id = 'product-1'
                """,
                (website_id,),
            ).fetchone()
            count = connection.execute(
                """
                SELECT COUNT(*) AS total
                FROM store_products p
                JOIN ecommerce_stores s ON s.id = p.ecommerce_store_id
                WHERE s.website_id = %s
                """,
                (website_id,),
            ).fetchone()

        assert website is not None
        assert product is not None
        assert count is not None
        assert website["crawl_status"] == "completed"
        assert website["technologies"] == ["shopify"]
        assert website["last_crawl_error"] is None
        assert website["timestamp_type"] == "timestamp with time zone"
        assert product["title"] == "Product version 2"
        assert product["price_cents"] == 1999
        assert product["status"] == "active"
        assert product["is_available"] is True
        assert product["external_id"] == "product-1"
        assert product["handle"] == "product-one"
        assert product["last_seen_at"].tzinfo is not None
        assert count["total"] == 1
    finally:
        _cleanup(repository, domain)


def test_repository_marks_failed_and_blocked_states(repository: PostgresRepository) -> None:
    failed_domain = "scraper-failed.example.com"
    blocked_domain = "scraper-blocked.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=failed_domain,
                    canonical_url=f"https://{failed_domain}",
                    source="integration_test",
                ),
                DiscoveryRecord(
                    domain=blocked_domain,
                    canonical_url=f"https://{blocked_domain}",
                    source="integration_test",
                ),
            ]
        )
        with repository.connection() as connection:
            ids = connection.execute(
                "SELECT id FROM websites WHERE domain = ANY(%s)",
                ([failed_domain, blocked_domain],),
            ).fetchall()
        targets = {
            target.domain: target
            for target in repository.claim_due_websites([int(row["id"]) for row in ids])
        }
        repository.mark_failed(
            targets[failed_domain].id,
            targets[failed_domain].claim_token,
            "temporary timeout",
        )
        repository.mark_blocked(
            targets[blocked_domain].id,
            targets[blocked_domain].claim_token,
            "robots denied",
        )

        with repository.connection() as connection:
            rows = connection.execute(
                "SELECT domain, crawl_status, next_crawl_at FROM websites WHERE domain = ANY(%s)",
                ([failed_domain, blocked_domain],),
            ).fetchall()
        states = {row["domain"]: row for row in rows}
        assert states[failed_domain]["crawl_status"] == "failed"
        assert states[failed_domain]["next_crawl_at"] is not None
        assert states[blocked_domain]["crawl_status"] == "blocked"
        assert states[blocked_domain]["next_crawl_at"] is None
    finally:
        _cleanup(repository, failed_domain, blocked_domain)


def test_repository_recovers_only_expired_claim_leases(repository: PostgresRepository) -> None:
    stale_domain = "scraper-stale.example.com"
    current_domain = "scraper-current.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=stale_domain,
                    canonical_url=f"https://{stale_domain}",
                    source="integration_test",
                ),
                DiscoveryRecord(
                    domain=current_domain,
                    canonical_url=f"https://{current_domain}",
                    source="integration_test",
                ),
            ]
        )
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'crawling', claim_token = gen_random_uuid(),
                    claimed_at = NOW() - INTERVAL '3 hours',
                    claim_lease_expires_at = NOW() - INTERVAL '2 hours',
                    updated_at = NOW() - INTERVAL '3 hours'
                WHERE domain = %s
                """,
                (stale_domain,),
            )
            connection.execute(
                """
                UPDATE websites SET crawl_status = 'crawling', claim_token = gen_random_uuid(),
                    claimed_at = NOW(), claim_lease_expires_at = NOW() + INTERVAL '1 hour',
                    updated_at = NOW()
                WHERE domain = %s
                """,
                (current_domain,),
            )
            connection.commit()

        assert repository.recover_stale_claims() == 1

        with repository.connection() as connection:
            rows = connection.execute(
                "SELECT domain, crawl_status FROM websites WHERE domain = ANY(%s)",
                ([stale_domain, current_domain],),
            ).fetchall()
        states = {row["domain"]: row["crawl_status"] for row in rows}
        assert states[stale_domain] == "failed"
        assert states[current_domain] == "crawling"
    finally:
        _cleanup(repository, stale_domain, current_domain)


def test_stale_worker_cannot_persist_or_fail_replacement_claim(
    repository: PostgresRepository,
) -> None:
    domain = "scraper-fence.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        website_id = website["id"]
        stale_target = repository.claim_due_websites([website_id])[0]
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET claim_lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website_id,),
            )
            connection.commit()
        assert repository.recover_stale_claims() == 1
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website_id,),
            )
            connection.commit()
        replacement = repository.claim_due_websites([website_id])[0]

        with pytest.raises(ClaimLostError):
            repository.persist_result(stale_target, _result(domain, "Stale product"))
        repository.mark_failed(stale_target.id, stale_target.claim_token, "stale failure")

        with repository.connection() as connection:
            row = connection.execute(
                "SELECT claim_token, crawl_status FROM websites WHERE id = %s",
                (website_id,),
            ).fetchone()
        assert row is not None
        assert str(row["claim_token"]) == str(replacement.claim_token)
        assert row["crawl_status"] == "crawling"
    finally:
        _cleanup(repository, domain)


def test_expired_lease_is_fenced_before_recovery_runs(repository: PostgresRepository) -> None:
    domain = "scraper-expired-fence.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        target = repository.claim_due_websites([website["id"]])[0]
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET claim_lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website["id"],),
            )
            connection.commit()

        with pytest.raises(ClaimLostError):
            repository.persist_result(target, _result(domain, "Expired product"))
        repository.mark_failed(target.id, target.claim_token, "expired failure")

        with repository.connection() as connection:
            row = connection.execute(
                "SELECT crawl_status, claim_token FROM websites WHERE id = %s",
                (website["id"],),
            ).fetchone()
        assert row is not None
        assert row["crawl_status"] == "crawling"
        assert str(row["claim_token"]) == str(target.claim_token)
    finally:
        _cleanup(repository, domain)


def test_skip_locked_claims_do_not_overlap(repository: PostgresRepository) -> None:
    domains = ["scraper-lock-one.example.com", "scraper-lock-two.example.com"]
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
                for domain in domains
            ]
        )
        with repository.connection() as first, repository.connection() as second:
            first.execute("BEGIN")
            locked = first.execute(
                """
                SELECT id FROM websites
                WHERE domain = %s
                FOR UPDATE SKIP LOCKED
                """,
                (domains[0],),
            ).fetchone()
            assert locked is not None

            rows = second.execute(
                """
                SELECT domain FROM websites
                WHERE domain = ANY(%s)
                FOR UPDATE SKIP LOCKED
                """,
                (domains,),
            ).fetchall()
            assert [row["domain"] for row in rows] == [domains[1]]
            first.rollback()
            second.rollback()
    finally:
        _cleanup(repository, *domains)


def test_complete_snapshot_reconciles_null_and_old_products(repository: PostgresRepository) -> None:
    domain = "scraper-reconcile.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(target, _result(domain, "Current product"))
        with repository.connection() as connection:
            store = connection.execute(
                "SELECT id FROM ecommerce_stores WHERE website_id = %s",
                (website["id"],),
            ).fetchone()
            assert store is not None
            connection.execute(
                """
                INSERT INTO store_products (
                    ecommerce_store_id, external_id, title, status, is_available,
                    first_seen_at, last_seen_at, created_at, updated_at
                ) VALUES (
                    %s, 'legacy-null', 'Legacy null', 'active', TRUE,
                    NOW(), NULL, NOW(), NOW()
                )
                """,
                (store["id"],),
            )
            connection.execute(
                """
                INSERT INTO store_products (
                    ecommerce_store_id, external_id, title, status, is_available,
                    first_seen_at, last_seen_at, created_at, updated_at
                ) VALUES (
                    %s, 'legacy-old', 'Legacy old', 'active', TRUE,
                    NOW(), NOW() - INTERVAL '1 day', NOW(), NOW()
                )
                """,
                (store["id"],),
            )
            connection.execute(
                """
                UPDATE websites SET crawl_status = 'pending',
                    next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website["id"],),
            )
            connection.commit()
        next_target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(next_target, _result(domain, "Current product updated"))

        with repository.connection() as connection:
            rows = connection.execute(
                """
                SELECT external_id, status, is_available
                FROM store_products WHERE ecommerce_store_id = %s
                """,
                (store["id"],),
            ).fetchall()
        states = {row["external_id"]: row for row in rows}
        assert states["product-1"]["status"] == "active"
        assert states["legacy-null"]["status"] == "unavailable"
        assert states["legacy-old"]["is_available"] is False
    finally:
        _cleanup(repository, domain)


def test_commerce_loss_deactivates_existing_store(repository: PostgresRepository) -> None:
    domain = "scraper-commerce-loss.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(target, _result(domain, "Product"))
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites SET crawl_status = 'pending',
                    next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website["id"],),
            )
            connection.commit()
        next_target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(
            next_target,
            CrawlResult(
                website=WebsiteRecord(
                    domain=domain,
                    canonical_url=f"https://{domain}",
                    source="integration_test",
                    website_status="active",
                    is_ecommerce=False,
                    last_seen_at=datetime.now(UTC),
                )
            ),
        )
        with repository.connection() as connection:
            store = connection.execute(
                "SELECT is_active FROM ecommerce_stores WHERE website_id = %s",
                (website["id"],),
            ).fetchone()
        assert store is not None
        assert store["is_active"] is False
    finally:
        _cleanup(repository, domain)


def test_signed_shopify_inventory_persists(repository: PostgresRepository) -> None:
    domain = "scraper-negative-inventory.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        target = repository.claim_due_websites([website["id"]])[0]
        result = _result(domain, "Backordered product")
        product = result.products[0].model_copy(update={"inventory_quantity": -3})
        repository.persist_result(target, result.model_copy(update={"products": [product]}))

        with repository.connection() as connection:
            inventory = connection.execute(
                """
                SELECT p.inventory_quantity
                FROM store_products p
                JOIN ecommerce_stores s ON s.id = p.ecommerce_store_id
                WHERE s.website_id = %s
                """,
                (website["id"],),
            ).fetchone()
        assert inventory is not None
        assert inventory["inventory_quantity"] == -3
    finally:
        _cleanup(repository, domain)


def test_store_scan_completion_and_failed_refund(repository: PostgresRepository) -> None:
    domain = "scraper-scan.example.com"
    user_email = "scraper-scan-user@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('Scan User', %s, 'not-used', 90, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (user_email,),
            ).fetchone()
            assert user is not None
            website_id = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW())
                RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website_id is not None
            website_id = website_id["id"]
            store_id = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'scan-integration-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website_id,),
            ).fetchone()
            assert store_id is not None
            store_id = store_id["id"]
            period = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'subscription_grant', 100, 0, 100, 'Period grant', %s, NOW())
                RETURNING id
                """,
                (user["id"], "scan-integration-period"),
            ).fetchone()
            assert period is not None
            charge = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'spend', -5, 95, 90, 'Deep scan', %s, NOW())
                RETURNING id
                """,
                (user["id"], "scan-integration-charge"),
            ).fetchone()
            assert charge is not None
            charge = charge["id"]
            for scan_id, key in [
                ("01J00000000000000000000001", "scan-integration-success"),
                ("01J00000000000000000000002", "scan-integration-failure"),
            ]:
                connection.execute(
                    """
                    INSERT INTO store_scan_requests (
                        id, user_id, ecommerce_store_id, idempotency_key, status,
                        credit_transaction_id, credit_period_transaction_id,
                        requested_at, created_at, updated_at
                    ) VALUES (%s, %s, %s, %s, 'queued', %s, %s, NOW(), NOW(), NOW())
                    """,
                    (scan_id, user["id"], store_id, key, charge, period["id"]),
                )
            connection.commit()

        targets = repository.claim_store_scans()
        success = next(target for target in targets if target.id.endswith("0001"))
        failure = next(target for target in targets if target.id.endswith("0002"))
        repository.complete_store_scan(
            success,
            [
                ProductRecord(
                    external_id="scan-product-1",
                    title="Scan Product",
                    inventory_quantity=-2,
                    last_seen_at=datetime.now(UTC),
                )
            ],
        )
        repository.fail_store_scan(failure, "catalog unavailable")
        repository.fail_store_scan(failure, "duplicate failure")

        with repository.connection() as connection:
            scans = connection.execute(
                """
                SELECT id, status, refund_transaction_id
                FROM store_scan_requests WHERE user_id = %s ORDER BY id
                """,
                (user["id"],),
            ).fetchall()
            balance = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (user["id"],),
            ).fetchone()
            assert balance is not None
            balance = balance["credits_balance"]
            refunds = connection.execute(
                """
                SELECT COUNT(*) AS total
                FROM credit_transactions
                WHERE idempotency_key LIKE %s AND user_id = %s
                """,
                ("deep-scan-refund:%", user["id"]),
            ).fetchone()
            notifications = connection.execute(
                """
                SELECT type FROM in_app_notifications
                WHERE user_id = %s AND reference_type = 'App\\Models\\StoreScanRequest'
                ORDER BY type
                """,
                (user["id"],),
            ).fetchall()
            assert refunds is not None
            refunds = refunds["total"]

        assert scans[0]["status"] == "completed"
        assert scans[1]["status"] == "failed"
        assert scans[1]["refund_transaction_id"] is not None
        assert balance == 95
        assert refunds == 1
        assert [row["type"] for row in notifications] == ["scan_completed", "scan_failed"]
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (user_email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_scan_notifications_respect_the_in_app_preference(
    repository: PostgresRepository,
) -> None:
    domain = "scraper-notify-pref.example.com"
    email = "scraper-notify-pref@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('Pref User', %s, 'unused', 100, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (email,),
            ).fetchone()
            assert user is not None
            connection.execute(
                """
                INSERT INTO notification_preferences (
                    user_id, inapp_on_scan_complete, created_at, updated_at
                ) VALUES (%s, FALSE, NOW(), NOW())
                """,
                (user["id"],),
            )
            website = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW()) RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website is not None
            store = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'notify-pref-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website["id"],),
            ).fetchone()
            assert store is not None
            connection.execute(
                """
                INSERT INTO store_scan_requests (
                    id, user_id, ecommerce_store_id, idempotency_key, status,
                    requested_at, created_at, updated_at
                ) VALUES ('01J00000000000000000000004', %s, %s, 'notify-pref-scan',
                    'queued', NOW(), NOW(), NOW())
                """,
                (user["id"], store["id"]),
            )
            connection.commit()

        target = next(
            item
            for item in repository.claim_store_scans()
            if item.id == "01J00000000000000000000004"
        )
        repository.complete_store_scan(target, [])

        with repository.connection() as connection:
            notifications = connection.execute(
                "SELECT COUNT(*) AS total FROM in_app_notifications WHERE user_id = %s",
                (user["id"],),
            ).fetchone()
            scan = connection.execute(
                "SELECT status FROM store_scan_requests WHERE id = %s",
                ("01J00000000000000000000004",),
            ).fetchone()

        assert notifications is not None
        assert notifications["total"] == 0
        assert scan is not None
        assert scan["status"] == "completed"
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_failed_scan_does_not_refund_into_a_new_credit_period(
    repository: PostgresRepository,
) -> None:
    domain = "scraper-cross-period-scan.example.com"
    email = "scraper-cross-period@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('Cross Period', %s, 'unused', 100, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (email,),
            ).fetchone()
            assert user is not None
            website = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW()) RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website is not None
            store = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'cross-period-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website["id"],),
            ).fetchone()
            assert store is not None
            old_period = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'subscription_grant', 100, 0, 100, 'Old period', %s, NOW())
                RETURNING id
                """,
                (user["id"], "cross-period-old"),
            ).fetchone()
            assert old_period is not None
            charge = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'spend', -5, 100, 95, 'Scan charge', %s, NOW())
                RETURNING id
                """,
                (user["id"], "cross-period-charge"),
            ).fetchone()
            assert charge is not None
            connection.execute(
                "UPDATE users SET credits_balance = 95 WHERE id = %s",
                (user["id"],),
            )
            connection.execute(
                """
                INSERT INTO store_scan_requests (
                    id, user_id, ecommerce_store_id, idempotency_key, status,
                    credit_transaction_id, credit_period_transaction_id,
                    requested_at, created_at, updated_at
                ) VALUES ('01J00000000000000000000003', %s, %s, 'cross-period-scan',
                    'queued', %s, %s, NOW(), NOW(), NOW())
                """,
                (user["id"], store["id"], charge["id"], old_period["id"]),
            )
            connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'monthly_reset', 5, 95, 100, 'New period', %s, NOW())
                """,
                (user["id"], "cross-period-new"),
            )
            connection.execute(
                "UPDATE users SET credits_balance = 100 WHERE id = %s",
                (user["id"],),
            )
            connection.commit()

        target = next(
            item
            for item in repository.claim_store_scans()
            if item.id == "01J00000000000000000000003"
        )
        repository.fail_store_scan(target, "late failure")

        with repository.connection() as connection:
            balance = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (user["id"],),
            ).fetchone()
            refund = connection.execute(
                """
                SELECT amount, metadata FROM credit_transactions
                WHERE idempotency_key = 'deep-scan-refund:01J00000000000000000000003'
                """
            ).fetchone()
        assert balance is not None
        assert balance["credits_balance"] == 100
        assert refund is not None
        assert refund["amount"] == 0
        assert refund["metadata"]["credit_period_current"] is False
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_scan_recovery_stops_requeueing_after_the_attempt_ceiling(
    repository: PostgresRepository,
) -> None:
    domain = "scraper-poison-scan.example.com"
    email = "scraper-poison@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('Poison Scan', %s, 'unused', 95, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (email,),
            ).fetchone()
            assert user is not None
            website = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW()) RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website is not None
            store = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'poison-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website["id"],),
            ).fetchone()
            assert store is not None
            period = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'subscription_grant', 100, 0, 100, 'Period', %s, NOW())
                RETURNING id
                """,
                (user["id"], "poison-period"),
            ).fetchone()
            assert period is not None
            charge = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'spend', -5, 100, 95, 'Deep scan', %s, NOW())
                RETURNING id
                """,
                (user["id"], "poison-charge"),
            ).fetchone()
            assert charge is not None

            # One expired lease below the ceiling, one at it, and one stranded
            # long enough to fall outside the recovery window.
            for scan_id, attempts, expired_hours in [
                ("01J00000000000000000000005", 1, 2),
                ("01J00000000000000000000006", 3, 2),
                ("01J00000000000000000000007", 1, 24 * 30),
            ]:
                connection.execute(
                    """
                    INSERT INTO store_scan_requests (
                        id, user_id, ecommerce_store_id, idempotency_key, status,
                        credit_transaction_id, credit_period_transaction_id,
                        attempts, claim_token, claim_lease_expires_at,
                        requested_at, created_at, updated_at
                    ) VALUES (
                        %s, %s, %s, %s, 'running', %s, %s, %s, gen_random_uuid(),
                        NOW() - (%s * INTERVAL '1 hour'), NOW(), NOW(), NOW()
                    )
                    """,
                    (
                        scan_id,
                        user["id"],
                        store["id"],
                        f"poison-{scan_id}",
                        charge["id"],
                        period["id"],
                        attempts,
                        expired_hours,
                    ),
                )
            connection.commit()

        # Only the under-ceiling scan inside the window is requeued.
        assert repository.recover_stale_store_scans() == 1

        with repository.connection() as connection:
            rows = connection.execute(
                """
                SELECT id, status, refund_transaction_id
                FROM store_scan_requests WHERE user_id = %s ORDER BY id
                """,
                (user["id"],),
            ).fetchall()
            balance = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (user["id"],),
            ).fetchone()

        states = {row["id"]: row for row in rows}
        assert states["01J00000000000000000000005"]["status"] == "queued"
        # Ceiling reached: terminally failed and the charge settled.
        assert states["01J00000000000000000000006"]["status"] == "failed"
        assert states["01J00000000000000000000006"]["refund_transaction_id"] is not None
        # Outside the recovery window: left alone rather than mass-requeued.
        assert states["01J00000000000000000000007"]["status"] == "running"
        assert balance is not None
        assert balance["credits_balance"] == 100
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_batch_ceiling_refunds_for_one_user_do_not_overwrite_each_other(
    repository: PostgresRepository,
) -> None:
    """Two exhausted scans for one user must both pay out.

    Recovery settles a whole batch in one transaction. Deriving balance_before
    from the batch's row snapshot and writing an absolute credits_balance meant
    the second refund recomputed from the same stale balance and erased the
    first, so the user silently lost credits and the ledger stopped reconciling.
    """
    domain = "scraper-batch-refund.example.com"
    email = "scraper-batch-refund@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('Batch Refund', %s, 'unused', 90, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (email,),
            ).fetchone()
            assert user is not None
            website = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW()) RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website is not None
            store = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'batch-refund-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website["id"],),
            ).fetchone()
            assert store is not None
            period = connection.execute(
                """
                INSERT INTO credit_transactions (
                    user_id, type, amount, balance_before, balance_after,
                    description, idempotency_key, created_at
                ) VALUES (%s, 'subscription_grant', 100, 0, 100, 'Period', %s, NOW())
                RETURNING id
                """,
                (user["id"], "batch-refund-period"),
            ).fetchone()
            assert period is not None

            # Two separate 5-credit charges, both exhausted, both in the window.
            for index, scan_id in enumerate(
                ["01J00000000000000000000010", "01J00000000000000000000011"]
            ):
                charge = connection.execute(
                    """
                    INSERT INTO credit_transactions (
                        user_id, type, amount, balance_before, balance_after,
                        description, idempotency_key, created_at
                    ) VALUES (%s, 'spend', -5, %s, %s, 'Deep scan', %s, NOW())
                    RETURNING id
                    """,
                    (user["id"], 100 - index * 5, 95 - index * 5, f"batch-refund-charge-{index}"),
                ).fetchone()
                assert charge is not None
                connection.execute(
                    """
                    INSERT INTO store_scan_requests (
                        id, user_id, ecommerce_store_id, idempotency_key, status,
                        credit_transaction_id, credit_period_transaction_id,
                        attempts, claim_token, claim_lease_expires_at,
                        requested_at, created_at, updated_at
                    ) VALUES (
                        %s, %s, %s, %s, 'running', %s, %s, 3, gen_random_uuid(),
                        NOW() - INTERVAL '2 hours', NOW(), NOW(), NOW()
                    )
                    """,
                    (
                        scan_id,
                        user["id"],
                        store["id"],
                        f"batch-refund-{scan_id}",
                        charge["id"],
                        period["id"],
                    ),
                )
            connection.commit()

        assert repository.recover_stale_store_scans() == 0

        with repository.connection() as connection:
            balance = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (user["id"],),
            ).fetchone()
            refunds = connection.execute(
                """
                SELECT amount, balance_before, balance_after
                FROM credit_transactions
                WHERE user_id = %s AND type = 'refund'
                ORDER BY id
                """,
                (user["id"],),
            ).fetchall()
            scans = connection.execute(
                """
                SELECT status, refund_transaction_id
                FROM store_scan_requests WHERE user_id = %s ORDER BY id
                """,
                (user["id"],),
            ).fetchall()
            notifications = connection.execute(
                "SELECT type FROM in_app_notifications WHERE user_id = %s",
                (user["id"],),
            ).fetchall()

        assert balance is not None
        # Both 5-credit refunds must land: 90 + 5 + 5.
        assert balance["credits_balance"] == 100
        assert [row["amount"] for row in refunds] == [5, 5]
        # The ledger chain must be contiguous, not two rows claiming 90 -> 95.
        assert [(row["balance_before"], row["balance_after"]) for row in refunds] == [
            (90, 95),
            (95, 100),
        ]
        assert [row["status"] for row in scans] == ["failed", "failed"]
        assert all(row["refund_transaction_id"] is not None for row in scans)
        # Terminal failure must still tell the user, like the worker path does.
        assert [row["type"] for row in notifications] == ["scan_failed", "scan_failed"]
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_failed_scan_without_a_charge_row_is_still_recorded(
    repository: PostgresRepository,
) -> None:
    """A NULL credit_transaction_id must not swallow the failure.

    credit_transaction_id is nullable with nullOnDelete, and an inner join here
    left the scan 'running' with a live claim, so a permanent error was retried
    all the way to the attempt ceiling instead of being reported.
    """
    domain = "scraper-no-charge.example.com"
    email = "scraper-no-charge@example.com"
    try:
        with repository.connection() as connection:
            user = connection.execute(
                """
                INSERT INTO users (
                    name, email, password, credits_balance, credits_monthly_quota,
                    is_active, created_at, updated_at
                ) VALUES ('No Charge', %s, 'unused', 50, 100, TRUE, NOW(), NOW())
                RETURNING id
                """,
                (email,),
            ).fetchone()
            assert user is not None
            website = connection.execute(
                """
                INSERT INTO websites (
                    domain, canonical_url, website_status, is_ecommerce, platform,
                    source, crawl_status, discovered_at, created_at, updated_at
                ) VALUES (%s, %s, 'active', TRUE, 'shopify', 'integration_test',
                    'completed', NOW(), NOW(), NOW()) RETURNING id
                """,
                (domain, f"https://{domain}"),
            ).fetchone()
            assert website is not None
            store = connection.execute(
                """
                INSERT INTO ecommerce_stores (
                    website_id, platform, platform_store_id, crawl_status,
                    is_active, created_at, updated_at
                ) VALUES (%s, 'shopify', 'no-charge-store', 'completed', TRUE, NOW(), NOW())
                RETURNING id
                """,
                (website["id"],),
            ).fetchone()
            assert store is not None
            connection.execute(
                """
                INSERT INTO store_scan_requests (
                    id, user_id, ecommerce_store_id, idempotency_key, status,
                    credit_transaction_id, requested_at, created_at, updated_at
                ) VALUES ('01J00000000000000000000012', %s, %s, 'no-charge-scan',
                    'queued', NULL, NOW(), NOW(), NOW())
                """,
                (user["id"], store["id"]),
            )
            connection.commit()

        target = next(
            item
            for item in repository.claim_store_scans()
            if item.id == "01J00000000000000000000012"
        )
        repository.fail_store_scan(target, "catalog permanently unavailable")

        with repository.connection() as connection:
            scan = connection.execute(
                """
                SELECT status, error_message, refund_transaction_id, claim_token
                FROM store_scan_requests WHERE id = %s
                """,
                ("01J00000000000000000000012",),
            ).fetchone()
            balance = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (user["id"],),
            ).fetchone()
            notifications = connection.execute(
                "SELECT type FROM in_app_notifications WHERE user_id = %s",
                (user["id"],),
            ).fetchall()

        assert scan is not None
        assert scan["status"] == "failed"
        assert scan["error_message"] == "catalog permanently unavailable"
        assert scan["refund_transaction_id"] is None
        assert scan["claim_token"] is None
        assert balance is not None
        assert balance["credits_balance"] == 50
        assert [row["type"] for row in notifications] == ["scan_failed"]
    finally:
        with repository.connection() as connection:
            connection.execute("DELETE FROM users WHERE email = %s", (email,))
            connection.execute("DELETE FROM websites WHERE domain = %s", (domain,))
            connection.commit()


def test_crawl_attempt_ceiling_blocks_poison_targets_and_resets_on_success(
    repository: PostgresRepository,
) -> None:
    """Website crawls need the ceiling deep scans already had.

    crawl_attempts is incremented on every claim, so it only works as a ceiling
    if a successful persist resets it. Otherwise a healthy site would retire
    after enough recrawls.
    """
    poison_domain = "scraper-poison-crawl.example.com"
    healthy_domain = "scraper-healthy-crawl.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=poison_domain,
                    canonical_url=f"https://{poison_domain}",
                    source="integration_test",
                ),
                DiscoveryRecord(
                    domain=healthy_domain,
                    canonical_url=f"https://{healthy_domain}",
                    source="integration_test",
                ),
            ]
        )
        with repository.connection() as connection:
            rows = connection.execute(
                "SELECT id, domain FROM websites WHERE domain = ANY(%s)",
                ([poison_domain, healthy_domain],),
            ).fetchall()
            ids = {row["domain"]: int(row["id"]) for row in rows}
            # One attempt below the ceiling; the next failure must park it.
            connection.execute(
                "UPDATE websites SET crawl_attempts = 4 WHERE id = %s",
                (ids[poison_domain],),
            )
            # Already past the ceiling, so it must not be claimable at all.
            connection.execute(
                "UPDATE websites SET crawl_attempts = 9 WHERE id = %s",
                (ids[healthy_domain],),
            )
            connection.commit()

        assert repository.claim_due_websites([ids[healthy_domain]]) == []

        target = repository.claim_due_websites([ids[poison_domain]])[0]
        repository.mark_failed(target.id, target.claim_token, "still broken")

        with repository.connection() as connection:
            poisoned = connection.execute(
                "SELECT crawl_status, next_crawl_at FROM websites WHERE id = %s",
                (ids[poison_domain],),
            ).fetchone()
        assert poisoned is not None
        assert poisoned["crawl_status"] == "blocked"
        assert poisoned["next_crawl_at"] is None

        # A successful crawl clears the counter so recrawls never exhaust it.
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'pending', crawl_attempts = 4,
                    next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (ids[healthy_domain],),
            )
            connection.commit()
        healthy_target = repository.claim_due_websites([ids[healthy_domain]])[0]
        repository.persist_result(healthy_target, _result(healthy_domain, "Healthy product"))

        with repository.connection() as connection:
            healthy = connection.execute(
                "SELECT crawl_status, crawl_attempts FROM websites WHERE id = %s",
                (ids[healthy_domain],),
            ).fetchone()
        assert healthy is not None
        assert healthy["crawl_status"] == "completed"
        assert healthy["crawl_attempts"] == 0
    finally:
        _cleanup(repository, poison_domain, healthy_domain)


def test_stale_claim_recovery_ignores_rows_outside_the_window(
    repository: PostgresRepository,
) -> None:
    """Recovery must not mass-requeue a long-stranded backlog on one tick."""
    recent_domain = "scraper-recent-lease.example.com"
    ancient_domain = "scraper-ancient-lease.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=recent_domain,
                    canonical_url=f"https://{recent_domain}",
                    source="integration_test",
                ),
                DiscoveryRecord(
                    domain=ancient_domain,
                    canonical_url=f"https://{ancient_domain}",
                    source="integration_test",
                ),
            ]
        )
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'crawling', claim_token = gen_random_uuid(),
                    claimed_at = NOW() - INTERVAL '3 hours',
                    claim_lease_expires_at = NOW() - INTERVAL '2 hours'
                WHERE domain = %s
                """,
                (recent_domain,),
            )
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'crawling', claim_token = gen_random_uuid(),
                    claimed_at = NOW() - INTERVAL '200 days',
                    claim_lease_expires_at = NOW() - INTERVAL '199 days'
                WHERE domain = %s
                """,
                (ancient_domain,),
            )
            connection.commit()

        assert repository.recover_stale_claims() == 1

        with repository.connection() as connection:
            rows = connection.execute(
                "SELECT domain, crawl_status FROM websites WHERE domain = ANY(%s)",
                ([recent_domain, ancient_domain],),
            ).fetchall()
        states = {row["domain"]: row["crawl_status"] for row in rows}
        assert states[recent_domain] == "failed"
        assert states[ancient_domain] == "crawling"
    finally:
        _cleanup(repository, recent_domain, ancient_domain)


def test_product_batches_upsert_identically_to_the_sequential_path(
    repository: PostgresRepository,
) -> None:
    """Batched upserts must preserve identity resolution and first_seen_at."""
    domain = "scraper-batch-products.example.com"
    try:
        repository.enqueue_discoveries(
            [
                DiscoveryRecord(
                    domain=domain, canonical_url=f"https://{domain}", source="integration_test"
                )
            ]
        )
        with repository.connection() as connection:
            website = connection.execute(
                "SELECT id FROM websites WHERE domain = %s",
                (domain,),
            ).fetchone()
        assert website is not None
        now = datetime.now(UTC)
        products = [
            ProductRecord(
                external_id=f"batch-product-{index}",
                handle=f"batch-handle-{index}",
                title=f"Batch Product {index}",
                currency_code="USD",
                price_cents=1000 + index,
                last_seen_at=now,
            )
            for index in range(12)
        ]
        target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(
            target,
            _result(domain, "seed").model_copy(update={"products": products}),
        )

        with repository.connection() as connection:
            store = connection.execute(
                "SELECT id FROM ecommerce_stores WHERE website_id = %s",
                (website["id"],),
            ).fetchone()
            assert store is not None
            first_pass = connection.execute(
                """
                SELECT external_id, price_cents, first_seen_at
                FROM store_products WHERE ecommerce_store_id = %s ORDER BY external_id
                """,
                (store["id"],),
            ).fetchall()
        assert len(first_pass) == 12
        original_first_seen = {row["external_id"]: row["first_seen_at"] for row in first_pass}
        assert {row["external_id"]: row["price_cents"] for row in first_pass} == {
            f"batch-product-{index}": 1000 + index for index in range(12)
        }

        # Re-crawl the same catalogue with new prices: rows must update in place
        # and keep their original first_seen_at.
        with repository.connection() as connection:
            connection.execute(
                """
                UPDATE websites SET crawl_status = 'pending',
                    next_crawl_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
                WHERE id = %s
                """,
                (website["id"],),
            )
            connection.commit()
        repriced = [
            product.model_copy(update={"price_cents": 5000 + index})
            for index, product in enumerate(products)
        ]
        next_target = repository.claim_due_websites([website["id"]])[0]
        repository.persist_result(
            next_target,
            _result(domain, "seed").model_copy(update={"products": repriced}),
        )

        with repository.connection() as connection:
            second_pass = connection.execute(
                """
                SELECT external_id, price_cents, first_seen_at, status
                FROM store_products WHERE ecommerce_store_id = %s ORDER BY external_id
                """,
                (store["id"],),
            ).fetchall()

        assert len(second_pass) == 12
        assert {row["external_id"]: row["price_cents"] for row in second_pass} == {
            f"batch-product-{index}": 5000 + index for index in range(12)
        }
        assert all(row["status"] == "active" for row in second_pass)
        assert {row["external_id"]: row["first_seen_at"] for row in second_pass} == (
            original_first_seen
        )
    finally:
        _cleanup(repository, domain)


def _result(domain: str, title: str, external_id: str | None = "product-1") -> CrawlResult:
    now = datetime.now(UTC)
    return CrawlResult(
        website=WebsiteRecord(
            domain=domain,
            canonical_url=f"https://{domain}",
            source="integration_test",
            source_external_id="integration-1",
            website_status="active",
            is_ecommerce=True,
            platform="shopify",
            technologies=["shopify"],
            source_payload={"worker": "pytest"},
            last_seen_at=now,
        ),
        store=EcommerceStoreRecord(
            platform="shopify",
            platform_store_id="integration-store-1",
            store_name="Integration Store",
            currency_code="USD",
            product_count=1,
        ),
        products=[
            ProductRecord(
                external_id=external_id,
                handle="product-one",
                title=title,
                currency_code="USD",
                price_cents=1999,
                last_seen_at=now,
            )
        ],
        products_complete=True,
    )


def _cleanup(repository: PostgresRepository, *domains: str) -> None:
    with repository.connection() as connection:
        connection.execute("DELETE FROM websites WHERE domain = ANY(%s)", (list(domains),))
        connection.commit()
