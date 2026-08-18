import secrets
import time
from collections.abc import Sequence
from contextlib import AbstractContextManager
from datetime import UTC, datetime, timedelta
from typing import Any
from uuid import UUID, uuid4

import psycopg
import structlog
from psycopg.rows import dict_row
from psycopg.types.json import Jsonb
from psycopg_pool import ConnectionPool

from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.domain.errors import (
    ClaimLostError,
    DataConflictError,
    SchemaCompatibilityError,
)
from sales_spy_scrapers.domain.models import (
    CrawlResult,
    CrawlTarget,
    DiscoveryRecord,
    EcommerceStoreRecord,
    ProductRecord,
    StoreScanTarget,
    WebsiteRecord,
)

log = structlog.get_logger(__name__)


def _new_ulid() -> str:
    alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
    value = (int(time.time() * 1000) << 80) | secrets.randbits(80)
    encoded = []
    for _ in range(26):
        encoded.append(alphabet[value & 31])
        value >>= 5
    return "".join(reversed(encoded))


class PostgresRepository:
    def __init__(self, settings: Settings) -> None:
        self._dsn = settings.database_url.get_secret_value()
        self._claim_batch_size = settings.claim_batch_size
        self._crawl_max_attempts = settings.crawl_max_attempts
        self._crawl_recovery_window_days = settings.crawl_recovery_window_days
        self._success_recrawl = timedelta(hours=settings.success_recrawl_hours)
        self._failed_retry = timedelta(minutes=settings.failed_retry_minutes)
        self._stale_claim = timedelta(minutes=settings.stale_claim_minutes)
        self._connect_timeout = settings.database_connect_timeout_seconds
        self._statement_timeout_ms = settings.database_statement_timeout_seconds * 1000
        self._scan_claim_batch_size = settings.scan_claim_batch_size
        self._scan_max_attempts = settings.scan_max_attempts
        self._scan_recovery_window_days = settings.scan_recovery_window_days
        self._product_batch_size = settings.product_upsert_batch_size
        # Pooled rather than one connect() per call: every repository method
        # opens a connection, so an unpooled crawl tick pays a TCP + TLS + auth
        # handshake per claim, persist, and mark. Opened lazily so constructing
        # the repository never requires a reachable database.
        self._pool = ConnectionPool(
            conninfo=self._dsn,
            min_size=settings.database_pool_min_size,
            max_size=settings.database_pool_max_size,
            kwargs={
                "row_factory": dict_row,
                "connect_timeout": self._connect_timeout,
                "options": (f"-c statement_timeout={self._statement_timeout_ms} -c timezone=UTC"),
            },
            open=False,
        )

    def connection(self) -> AbstractContextManager[psycopg.Connection[Any]]:
        """Borrow a pooled connection; it is returned to the pool on exit.

        Matches the previous contract: the context manager commits on a clean
        exit and rolls back on an exception.
        """
        if self._pool.closed:
            self._pool.open()
        return self._pool.connection()

    def close(self) -> None:
        if not self._pool.closed:
            self._pool.close()

    def assert_schema_compatible(self, expected_migration: str) -> None:
        with self.connection() as connection:
            applied = connection.execute(
                "SELECT EXISTS (SELECT 1 FROM migrations WHERE migration = %s) AS applied",
                (expected_migration,),
            ).fetchone()
            if not applied or not applied["applied"]:
                raise SchemaCompatibilityError(
                    f"required migration is not applied: {expected_migration}"
                )

            required = {
                "websites": {
                    "domain",
                    "crawl_status",
                    "crawl_attempts",
                    "next_crawl_at",
                    "claim_token",
                    "claim_lease_expires_at",
                },
                "ecommerce_stores": {"website_id", "platform"},
                "store_products": {"ecommerce_store_id", "title", "inventory_quantity"},
                "store_scan_requests": {
                    "user_id",
                    "ecommerce_store_id",
                    "status",
                    "attempts",
                    "claim_token",
                    "claim_lease_expires_at",
                    "credit_transaction_id",
                    "credit_period_transaction_id",
                    "refund_transaction_id",
                },
                "in_app_notifications": {
                    "user_id",
                    "type",
                    "reference_type",
                    "reference_id",
                    "read_at",
                },
            }
            for table, columns in required.items():
                rows = connection.execute(
                    """
                    SELECT column_name FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = %s
                    """,
                    (table,),
                ).fetchall()
                actual = {item["column_name"] for item in rows}
                missing = columns - actual
                if missing:
                    raise SchemaCompatibilityError(f"{table} is missing columns: {sorted(missing)}")

            required_types = {
                ("websites", "source_payload"): "jsonb",
                ("websites", "claim_token"): "uuid",
                ("websites", "claim_lease_expires_at"): "timestamp with time zone",
                ("store_products", "last_seen_at"): "timestamp with time zone",
            }
            for (table, column), expected_type in required_types.items():
                row = connection.execute(
                    """
                    SELECT data_type FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = %s AND column_name = %s
                    """,
                    (table, column),
                ).fetchone()
                if row is None or row["data_type"] != expected_type:
                    raise SchemaCompatibilityError(f"{table}.{column} must be {expected_type}")

    def claim_due_websites(self, website_ids: Sequence[int] | None = None) -> list[CrawlTarget]:
        if website_ids is not None and not website_ids:
            return []
        with self.connection() as connection, connection.transaction():
            rows = connection.execute(
                """
                    SELECT id, domain, canonical_url, source, source_external_id, crawl_attempts
                    FROM websites
                    WHERE crawl_status IN ('pending', 'failed', 'completed')
                      AND (next_crawl_at IS NULL OR next_crawl_at <= CURRENT_TIMESTAMP)
                      AND crawl_attempts < %s
                      AND (%s::bigint[] IS NULL OR id = ANY(%s))
                    ORDER BY COALESCE(next_crawl_at, discovered_at), id
                    FOR UPDATE SKIP LOCKED
                    LIMIT %s
                    """,
                (
                    self._crawl_max_attempts,
                    list(website_ids) if website_ids else None,
                    list(website_ids) if website_ids else None,
                    self._claim_batch_size,
                ),
            ).fetchall()
            if not rows:
                return []
            targets: list[CrawlTarget] = []
            for row in rows:
                claim_token = uuid4()
                claimed = connection.execute(
                    """
                    UPDATE websites
                    SET crawl_status = 'crawling', crawl_attempts = crawl_attempts + 1,
                        claim_token = %s, claimed_at = CURRENT_TIMESTAMP,
                        claim_lease_expires_at = CURRENT_TIMESTAMP + (%s * INTERVAL '1 minute'),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = %s
                    RETURNING claim_lease_expires_at
                    """,
                    (claim_token, self._stale_claim.total_seconds() / 60, row["id"]),
                ).fetchone()
                if claimed is None:
                    continue
                targets.append(
                    CrawlTarget.model_validate(
                        {
                            **row,
                            "claim_token": claim_token,
                            "claim_lease_expires_at": claimed["claim_lease_expires_at"],
                        }
                    )
                )
            return targets

    def recover_stale_claims(self) -> int:
        """Requeue websites whose worker died before writing a terminal status.

        Bounded the same way scan recovery is: a recent-only window plus a row
        cap, so a backlog stranded by a long outage cannot all requeue on one
        tick and stampede the next claim. Targets past the attempt ceiling are
        parked as 'blocked' instead of being retried forever.
        """
        now = datetime.now(UTC)
        with self.connection() as connection:
            cursor = connection.execute(
                """
                UPDATE websites
                SET crawl_status = CASE
                        WHEN crawl_attempts >= %s THEN 'blocked' ELSE 'failed'
                    END,
                    last_crawl_error = 'Worker claim lease expired before completion',
                    next_crawl_at = CASE
                        WHEN crawl_attempts >= %s THEN NULL ELSE %s
                    END,
                    claim_token = NULL, claimed_at = NULL, claim_lease_expires_at = NULL,
                    updated_at = %s
                WHERE id IN (
                    SELECT id FROM websites
                    WHERE crawl_status = 'crawling'
                      AND claim_lease_expires_at < CURRENT_TIMESTAMP
                      AND claim_lease_expires_at
                          > CURRENT_TIMESTAMP - (%s * INTERVAL '1 day')
                    ORDER BY claim_lease_expires_at
                    FOR UPDATE SKIP LOCKED
                    LIMIT %s
                )
                """,
                (
                    self._crawl_max_attempts,
                    self._crawl_max_attempts,
                    now + self._failed_retry,
                    now,
                    self._crawl_recovery_window_days,
                    self._claim_batch_size * 10,
                ),
            )
            connection.commit()
            return cursor.rowcount

    def claim_store_scans(self) -> list[StoreScanTarget]:
        with self.connection() as connection, connection.transaction():
            rows = connection.execute(
                """
                SELECT r.id, r.user_id, r.ecommerce_store_id,
                       w.domain, w.canonical_url
                FROM store_scan_requests r
                JOIN ecommerce_stores s ON s.id = r.ecommerce_store_id
                JOIN websites w ON w.id = s.website_id
                WHERE r.status = 'queued'
                ORDER BY r.requested_at, r.id
                FOR UPDATE OF r SKIP LOCKED
                LIMIT %s
                """,
                (self._scan_claim_batch_size,),
            ).fetchall()
            targets: list[StoreScanTarget] = []
            for row in rows:
                token = uuid4()
                claimed = connection.execute(
                    """
                    UPDATE store_scan_requests
                    SET status = 'running', claim_token = %s, started_at = CURRENT_TIMESTAMP,
                        claim_lease_expires_at = CURRENT_TIMESTAMP + (%s * INTERVAL '1 minute'),
                        attempts = attempts + 1,
                        error_message = NULL, updated_at = CURRENT_TIMESTAMP
                    WHERE id = %s AND status = 'queued'
                    RETURNING claim_lease_expires_at
                    """,
                    (token, self._stale_claim.total_seconds() / 60, row["id"]),
                ).fetchone()
                if claimed:
                    targets.append(
                        StoreScanTarget.model_validate(
                            {
                                **row,
                                "claim_token": token,
                                "claim_lease_expires_at": claimed["claim_lease_expires_at"],
                            }
                        )
                    )
            return targets

    def recover_stale_store_scans(self) -> int:
        """Requeue scans whose worker died, and terminally fail poison scans.

        A scan that kills its worker before writing status would otherwise be
        re-claimed forever. Recovery is also bounded to a recent window so a
        backlog of long-stranded rows cannot all requeue on one tick.
        """
        recovered = 0
        with self.connection() as connection, connection.transaction():
            rows = connection.execute(
                """
                SELECT r.id, r.attempts, r.user_id, r.credit_transaction_id,
                       r.refund_transaction_id, r.credit_period_transaction_id,
                       c.amount AS charged_amount, u.credits_monthly_quota,
                       w.domain
                FROM store_scan_requests r
                LEFT JOIN credit_transactions c ON c.id = r.credit_transaction_id
                JOIN users u ON u.id = r.user_id
                JOIN ecommerce_stores s ON s.id = r.ecommerce_store_id
                JOIN websites w ON w.id = s.website_id
                WHERE r.status = 'running'
                  AND r.claim_lease_expires_at < CURRENT_TIMESTAMP
                  AND r.claim_lease_expires_at > CURRENT_TIMESTAMP - (%s * INTERVAL '1 day')
                ORDER BY r.id
                FOR UPDATE OF r, u SKIP LOCKED
                LIMIT %s
                """,
                (self._scan_recovery_window_days, self._scan_claim_batch_size * 10),
            ).fetchall()

            for row in rows:
                exhausted = int(row["attempts"]) >= self._scan_max_attempts
                if not exhausted:
                    connection.execute(
                        """
                        UPDATE store_scan_requests
                        SET status = 'queued', claim_token = NULL, started_at = NULL,
                            claim_lease_expires_at = NULL,
                            error_message = 'Worker claim lease expired before completion',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = %s
                        """,
                        (row["id"],),
                    )
                    recovered += 1
                    continue

                # Attempt ceiling reached: stop retrying and settle the charge.
                self._settle_failed_scan(
                    connection,
                    dict(row),
                    f"Abandoned after {self._scan_max_attempts} failed worker attempts",
                )

        return recovered

    def _settle_failed_scan(
        self,
        connection: psycopg.Connection[Any],
        scan: dict[str, Any],
        error: str,
        claim_token: UUID | str | None = None,
    ) -> bool:
        """Terminally fail one scan: refund the charge, then notify the user.

        Both the worker-reported failure and the attempt-ceiling recovery settle
        scans, so status, refund, and notification live here together. Splitting
        them let the recovery path silently skip the notification.
        """
        refund_id = (
            self._refund_scan_charge(connection, scan, scan["id"])
            if scan["credit_transaction_id"] is not None
            else None
        )

        if claim_token is None:
            updated = connection.execute(
                """
                UPDATE store_scan_requests
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP,
                    claim_token = NULL, claim_lease_expires_at = NULL,
                    refund_transaction_id = %s, error_message = %s,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
                RETURNING id
                """,
                (refund_id, error[:2000], scan["id"]),
            ).fetchone()
        else:
            updated = connection.execute(
                """
                UPDATE store_scan_requests
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP,
                    claim_token = NULL, claim_lease_expires_at = NULL,
                    refund_transaction_id = %s, error_message = %s,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = %s AND claim_token = %s
                RETURNING id
                """,
                (refund_id, error[:2000], scan["id"], claim_token),
            ).fetchone()
        if updated is None:
            return False

        self._create_scan_notification(
            connection,
            user_id=scan["user_id"],
            scan_id=scan["id"],
            domain=scan["domain"],
            notification_type="scan_failed",
            title="Store scan failed",
            message=(
                f"The deep scan for {scan['domain']} failed. "
                "Your scan credits were refunded when applicable."
            ),
        )
        return True

    def complete_store_scan(
        self,
        target: StoreScanTarget,
        products: Sequence[ProductRecord],
    ) -> None:
        now = datetime.now(UTC)
        with self.connection() as connection, connection.transaction():
            owned = connection.execute(
                """
                SELECT id FROM store_scan_requests
                WHERE id = %s AND status = 'running' AND claim_token = %s
                  AND claim_lease_expires_at >= CURRENT_TIMESTAMP
                FOR UPDATE
                """,
                (target.id, target.claim_token),
            ).fetchone()
            if owned is None:
                raise ClaimLostError(f"scan claim lease is no longer owned for request {target.id}")

            self._upsert_products(
                connection,
                target.ecommerce_store_id,
                products,
                now,
                True,
            )
            connection.execute(
                """
                UPDATE ecommerce_stores
                SET product_count = %s, last_product_sync_at = %s,
                    last_crawled_at = %s, crawl_status = 'completed',
                    last_crawl_error = NULL, updated_at = %s
                WHERE id = %s
                """,
                (len(products), now, now, now, target.ecommerce_store_id),
            )
            updated = connection.execute(
                """
                UPDATE store_scan_requests
                SET status = 'completed', completed_at = %s,
                    claim_token = NULL, claim_lease_expires_at = NULL,
                    updated_at = %s
                WHERE id = %s AND claim_token = %s
                  AND claim_lease_expires_at >= CURRENT_TIMESTAMP
                RETURNING id
                """,
                (now, now, target.id, target.claim_token),
            ).fetchone()
            if updated is None:
                raise ClaimLostError(f"scan claim lease expired for request {target.id}")
            self._create_scan_notification(
                connection,
                user_id=target.user_id,
                scan_id=target.id,
                domain=target.domain,
                notification_type="scan_completed",
                title="Store scan completed",
                message=f"The deep scan for {target.domain} completed successfully.",
            )

    def fail_store_scan(self, target: StoreScanTarget, error: str) -> None:
        with self.connection() as connection, connection.transaction():
            # LEFT JOIN: credit_transaction_id is nullable, and a scan whose
            # charge row is gone must still be recorded as failed rather than
            # silently retried until the attempt ceiling.
            scan = connection.execute(
                """
                SELECT r.id, r.user_id, r.credit_transaction_id, r.refund_transaction_id,
                       r.credit_period_transaction_id,
                       c.amount AS charged_amount, u.credits_monthly_quota
                FROM store_scan_requests r
                LEFT JOIN credit_transactions c ON c.id = r.credit_transaction_id
                JOIN users u ON u.id = r.user_id
                WHERE r.id = %s AND r.status = 'running' AND r.claim_token = %s
                  AND r.claim_lease_expires_at >= CURRENT_TIMESTAMP
                FOR UPDATE OF r, u
                """,
                (target.id, target.claim_token),
            ).fetchone()
            if scan is None:
                return

            self._settle_failed_scan(
                connection,
                {**dict(scan), "domain": target.domain},
                error,
                claim_token=target.claim_token,
            )

    def _refund_scan_charge(
        self,
        connection: psycopg.Connection[Any],
        scan: dict[str, Any],
        scan_id: str,
    ) -> Any:
        """Refund a failed scan's charge, once, and only within its credit period.

        A refund row is always written so the outcome is auditable, but the
        amount is zero when the charge belongs to an earlier period. Paying it
        into a freshly reset balance would inflate the user's credits.

        The balance moves with a relative UPDATE and the ledger chain is derived
        from its RETURNING value. Reading the balance from the caller's row
        snapshot would be wrong: recovery settles a whole batch inside one
        transaction, so two refunds for the same user would both start from the
        same stale balance and the second absolute write would erase the first.
        """
        if scan["refund_transaction_id"] is not None:
            return scan["refund_transaction_id"]

        idempotency_key = f"deep-scan-refund:{scan_id}"
        # The caller holds FOR UPDATE on this user row, so no concurrent
        # settlement can slip between this check and the insert below.
        existing = connection.execute(
            "SELECT id FROM credit_transactions WHERE idempotency_key = %s",
            (idempotency_key,),
        ).fetchone()
        if existing is not None:
            return existing["id"]

        charged_amount = abs(int(scan["charged_amount"]))
        unlimited = int(scan["credits_monthly_quota"]) == -1
        current_period = connection.execute(
            """
            SELECT id FROM credit_transactions
            WHERE user_id = %s AND type IN ('subscription_grant', 'monthly_reset')
            ORDER BY id DESC LIMIT 1
            """,
            (scan["user_id"],),
        ).fetchone()
        same_period = (
            current_period is not None
            and current_period["id"] == scan["credit_period_transaction_id"]
        )
        refundable_amount = 0 if (unlimited or not same_period) else charged_amount

        if refundable_amount:
            balance_row = connection.execute(
                """
                UPDATE users
                SET credits_balance = credits_balance + %s, updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
                RETURNING credits_balance
                """,
                (refundable_amount, scan["user_id"]),
            ).fetchone()
        else:
            balance_row = connection.execute(
                "SELECT credits_balance FROM users WHERE id = %s",
                (scan["user_id"],),
            ).fetchone()
        if balance_row is None:
            raise RuntimeError(f"refund target user is missing for scan {scan_id}")
        balance_after = int(balance_row["credits_balance"])
        balance_before = balance_after - refundable_amount

        refund = connection.execute(
            """
            INSERT INTO credit_transactions (
                user_id, type, amount, balance_before, balance_after,
                description, reference_type, reference_id,
                idempotency_key, metadata, created_at
            ) VALUES (
                %s, 'refund', %s, %s, %s,
                'Failed deep scan refund', 'App\\Models\\StoreScanRequest', %s,
                %s, %s, CURRENT_TIMESTAMP
            )
            RETURNING id
            """,
            (
                scan["user_id"],
                refundable_amount,
                balance_before,
                balance_after,
                scan_id,
                idempotency_key,
                Jsonb(
                    {
                        "scan_request_id": scan_id,
                        "original_charge_amount": charged_amount,
                        "credit_period_current": same_period,
                        "unlimited": unlimited,
                    }
                ),
            ),
        ).fetchone()
        if refund is None:
            raise RuntimeError("failed scan refund did not return an ID")

        return refund["id"]

    def _create_scan_notification(
        self,
        connection: psycopg.Connection[Any],
        *,
        user_id: int,
        scan_id: str,
        domain: str,
        notification_type: str,
        title: str,
        message: str,
    ) -> None:
        enabled = connection.execute(
            """
            SELECT COALESCE(
                (SELECT inapp_on_scan_complete FROM notification_preferences WHERE user_id = %s),
                TRUE
            ) AS enabled
            """,
            (user_id,),
        ).fetchone()
        if enabled is not None and not enabled["enabled"]:
            return

        connection.execute(
            """
            INSERT INTO in_app_notifications (
                id, user_id, type, title, message, reference_type,
                reference_id, data, created_at, updated_at
            ) VALUES (
                %s, %s, %s, %s, %s, 'App\\Models\\StoreScanRequest',
                %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT (user_id, type, reference_type, reference_id) DO NOTHING
            """,
            (
                _new_ulid(),
                user_id,
                notification_type,
                title,
                message,
                scan_id,
                Jsonb(
                    {
                        "scan_request_id": scan_id,
                        "domain": domain,
                        "status": "completed"
                        if notification_type == "scan_completed"
                        else "failed",
                    }
                ),
            ),
        )

    def enqueue_discoveries(self, discoveries: Sequence[DiscoveryRecord]) -> int:
        if not discoveries:
            return 0
        with self.connection() as connection, connection.transaction():
            # executemany pipelines the batch, so a Common Crawl page costs a
            # couple of round trips instead of one per discovered URL.
            connection.cursor().executemany(
                """
                    INSERT INTO websites (
                        domain, canonical_url, source, source_external_id, source_payload,
                        crawl_status, discovered_at, last_seen_at, next_crawl_at,
                        created_at, updated_at
                    ) VALUES (
                        %(domain)s, %(canonical_url)s, %(source)s, %(source_external_id)s,
                        %(source_payload)s, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP - INTERVAL '1 second',
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (domain) DO UPDATE SET
                        canonical_url = EXCLUDED.canonical_url,
                        source_payload = COALESCE(websites.source_payload, '{}'::jsonb)
                            || EXCLUDED.source_payload,
                        last_seen_at = EXCLUDED.last_seen_at,
                        next_crawl_at = CASE
                            WHEN websites.crawl_status = 'blocked' THEN websites.next_crawl_at
                            ELSE LEAST(websites.next_crawl_at, EXCLUDED.next_crawl_at)
                        END,
                        updated_at = CASE
                            WHEN websites.crawl_status = 'crawling' THEN websites.updated_at
                            ELSE EXCLUDED.updated_at
                        END
                    """,
                [self._discovery_params(discovery) for discovery in discoveries],
            )
        return len(discoveries)

    @staticmethod
    def _discovery_params(discovery: DiscoveryRecord) -> dict[str, Any]:
        data = discovery.model_dump(mode="json")
        data["source_payload"] = Jsonb(data["source_payload"])
        return data

    def persist_result(self, target: CrawlTarget, result: CrawlResult) -> int:
        website = result.website
        now = datetime.now(UTC)
        with self.connection() as connection, connection.transaction():
            website_row = connection.execute(
                """
                    UPDATE websites SET
                        canonical_url = %(canonical_url)s,
                        name = %(name)s,
                        description = %(description)s,
                        website_status = %(website_status)s,
                        is_ecommerce = %(is_ecommerce)s,
                        platform = %(platform)s,
                        cms = %(cms)s,
                        niche = %(niche)s,
                        country_code = %(country_code)s,
                        language_code = %(language_code)s,
                        email = %(email)s,
                        phone = %(phone)s,
                        contact_page_url = %(contact_page_url)s,
                        logo_url = %(logo_url)s,
                        favicon_url = %(favicon_url)s,
                        http_status = %(http_status)s,
                        crawl_status = 'completed',
                        technologies = %(technologies)s,
                        social_links = %(social_links)s,
                        source_payload = %(source_payload)s,
                        last_seen_at = %(last_seen_at)s,
                        last_crawled_at = %(last_crawled_at)s,
                        next_crawl_at = %(next_crawl_at)s,
                        last_crawl_error = NULL,
                        -- Reset on success so the ceiling counts *consecutive*
                        -- failures. A lifetime counter would retire every
                        -- healthy site once it had been recrawled that often.
                        crawl_attempts = 0,
                        claim_token = NULL, claimed_at = NULL, claim_lease_expires_at = NULL,
                        updated_at = %(updated_at)s
                    WHERE id = %(website_id)s AND claim_token = %(claim_token)s
                      AND crawl_status = 'crawling'
                      AND claim_lease_expires_at >= CURRENT_TIMESTAMP
                    RETURNING id
                    """,
                {
                    **_website_params(website, now, self._success_recrawl),
                    "website_id": target.id,
                    "claim_token": target.claim_token,
                },
            ).fetchone()
            if website_row is None:
                raise ClaimLostError(f"claim lease is no longer owned for website {target.id}")
            website_id = int(website_row["id"])

            if result.store:
                store_id = self._upsert_store(connection, website_id, result.store, now)
                self._upsert_products(
                    connection,
                    store_id,
                    result.products,
                    now,
                    result.products_complete,
                )
                if result.products_complete:
                    connection.execute(
                        """
                        UPDATE ecommerce_stores
                        SET last_product_sync_at = %s, updated_at = %s
                        WHERE id = %s
                        """,
                        (now, now, store_id),
                    )
            else:
                connection.execute(
                    """
                    UPDATE ecommerce_stores
                    SET is_active = FALSE, crawl_status = 'completed',
                        last_crawl_error = NULL, updated_at = %s
                    WHERE website_id = %s
                    """,
                    (now, website_id),
                )
            return int(website_id)

    def mark_failed(self, website_id: int, claim_token: UUID, error: str) -> None:
        """Record a crawl failure, parking the target once it exhausts retries.

        `crawl_attempts` counts consecutive failures (reset by a successful
        persist), so reaching the ceiling moves the row to 'blocked', which the
        claim predicate excludes. Otherwise a target that always fails would be
        retried every `failed_retry_minutes` forever.
        """
        bounded_error = error[:2000]
        with self.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = CASE
                        WHEN crawl_attempts >= %s THEN 'blocked' ELSE 'failed'
                    END,
                    last_crawl_error = %s,
                    next_crawl_at = CASE
                        WHEN crawl_attempts >= %s THEN NULL ELSE %s
                    END,
                    claim_token = NULL, claimed_at = NULL,
                    claim_lease_expires_at = NULL, updated_at = %s
                WHERE id = %s AND claim_token = %s AND crawl_status = 'crawling'
                  AND claim_lease_expires_at >= CURRENT_TIMESTAMP
                """,
                (
                    self._crawl_max_attempts,
                    bounded_error,
                    self._crawl_max_attempts,
                    datetime.now(UTC) + self._failed_retry,
                    datetime.now(UTC),
                    website_id,
                    claim_token,
                ),
            )
            connection.commit()

    def mark_blocked(self, website_id: int, claim_token: UUID, reason: str) -> None:
        with self.connection() as connection:
            connection.execute(
                """
                UPDATE websites
                SET crawl_status = 'blocked', last_crawl_error = %s,
                    next_crawl_at = NULL, claim_token = NULL, claimed_at = NULL,
                    claim_lease_expires_at = NULL, updated_at = %s
                WHERE id = %s AND claim_token = %s AND crawl_status = 'crawling'
                  AND claim_lease_expires_at >= CURRENT_TIMESTAMP
                """,
                (reason[:2000], datetime.now(UTC), website_id, claim_token),
            )
            connection.commit()

    def _upsert_store(
        self,
        connection: psycopg.Connection[Any],
        website_id: int,
        store: EcommerceStoreRecord,
        now: datetime,
    ) -> int:
        if store.platform_store_id:
            identity_owner = connection.execute(
                """
                SELECT website_id FROM ecommerce_stores
                WHERE platform = %s AND platform_store_id = %s
                FOR UPDATE
                """,
                (store.platform, store.platform_store_id),
            ).fetchone()
            if identity_owner and identity_owner["website_id"] != website_id:
                raise DataConflictError(
                    "platform store identity is already assigned to another website"
                )
        store_data = store.model_dump(mode="json")
        for field in ("payment_methods", "shipping_countries", "platform_metadata"):
            store_data[field] = Jsonb(store_data[field])

        row = connection.execute(
            """
            INSERT INTO ecommerce_stores (
                website_id, platform, platform_store_id, store_name, category,
                currency_code, product_count, collection_count, average_price_cents,
                minimum_price_cents, maximum_price_cents, has_discounted_products,
                accepts_payments, has_cart, crawl_status, payment_methods,
                shipping_countries, platform_metadata, last_crawled_at,
                next_crawl_at, created_at, updated_at
            ) VALUES (
                %(website_id)s, %(platform)s, %(platform_store_id)s, %(store_name)s,
                %(category)s, %(currency_code)s, %(product_count)s, %(collection_count)s,
                %(average_price_cents)s, %(minimum_price_cents)s, %(maximum_price_cents)s,
                %(has_discounted_products)s, %(accepts_payments)s, %(has_cart)s,
                'completed', %(payment_methods)s, %(shipping_countries)s,
                %(platform_metadata)s, %(last_crawled_at)s, %(next_crawl_at)s,
                %(created_at)s, %(updated_at)s
            )
            ON CONFLICT (website_id) DO UPDATE SET
                platform = EXCLUDED.platform,
                platform_store_id = EXCLUDED.platform_store_id,
                store_name = EXCLUDED.store_name,
                category = EXCLUDED.category,
                currency_code = EXCLUDED.currency_code,
                product_count = COALESCE(EXCLUDED.product_count, ecommerce_stores.product_count),
                collection_count = EXCLUDED.collection_count,
                average_price_cents = EXCLUDED.average_price_cents,
                minimum_price_cents = EXCLUDED.minimum_price_cents,
                maximum_price_cents = EXCLUDED.maximum_price_cents,
                has_discounted_products = EXCLUDED.has_discounted_products,
                accepts_payments = EXCLUDED.accepts_payments,
                has_cart = EXCLUDED.has_cart,
                crawl_status = 'completed',
                is_active = TRUE,
                last_crawl_error = NULL,
                payment_methods = EXCLUDED.payment_methods,
                shipping_countries = EXCLUDED.shipping_countries,
                platform_metadata = EXCLUDED.platform_metadata,
                last_crawled_at = EXCLUDED.last_crawled_at,
                next_crawl_at = EXCLUDED.next_crawl_at,
                updated_at = EXCLUDED.updated_at
            RETURNING id
            """,
            {
                "website_id": website_id,
                **store_data,
                "last_crawled_at": now,
                "next_crawl_at": now + self._success_recrawl,
                "created_at": now,
                "updated_at": now,
            },
        ).fetchone()
        if row is None:
            raise RuntimeError("store upsert returned no ID")
        return int(row["id"])

    def _upsert_products(
        self,
        connection: psycopg.Connection[Any],
        store_id: int,
        products: Sequence[ProductRecord],
        now: datetime,
        products_complete: bool,
    ) -> None:
        """Persist a product snapshot in batches.

        A deep scan can carry up to `deep_scan_product_limit` (25k) products.
        Resolving identities one product at a time cost a SELECT plus a write
        per row, so a full catalogue meant ~50k sequential round trips inside a
        single transaction. Each chunk now does one locking identity lookup and
        pipelines its writes with executemany.
        """
        for start in range(0, len(products), self._product_batch_size):
            self._upsert_product_chunk(
                connection,
                store_id,
                products[start : start + self._product_batch_size],
                now,
            )

        if products_complete:
            connection.execute(
                """
                UPDATE store_products
                SET status = 'unavailable', is_available = FALSE, updated_at = %s
                WHERE ecommerce_store_id = %s
                  AND (last_seen_at IS NULL OR last_seen_at < %s)
                """,
                (now, store_id, now),
            )

    def _upsert_product_chunk(
        self,
        connection: psycopg.Connection[Any],
        store_id: int,
        products: Sequence[ProductRecord],
        now: datetime,
    ) -> None:
        rows = [self._product_params(store_id, product, now) for product in products]
        external_ids = [p.external_id for p in products if p.external_id]
        handles = [p.handle for p in products if p.handle]
        if not external_ids and not handles:
            return

        existing_rows = connection.execute(
            """
            SELECT id, external_id, handle, first_seen_at FROM store_products
            WHERE ecommerce_store_id = %s
              AND (external_id = ANY(%s) OR handle = ANY(%s))
            ORDER BY id
            FOR UPDATE
            """,
            (store_id, external_ids, handles),
        ).fetchall()

        by_external: dict[str, dict[str, Any]] = {}
        by_handle: dict[str, dict[str, Any]] = {}
        for row in existing_rows:
            if row["external_id"] is not None:
                by_external.setdefault(row["external_id"], row)
            if row["handle"] is not None:
                by_handle.setdefault(row["handle"], row)

        updates: list[dict[str, Any]] = []
        inserts: list[dict[str, Any]] = []
        duplicate_ids: set[int] = set()
        claimed: set[int] = set()

        for product, data in zip(products, rows, strict=True):
            candidates: list[dict[str, Any]] = []
            if product.external_id and product.external_id in by_external:
                candidates.append(by_external[product.external_id])
            if product.handle and product.handle in by_handle:
                candidate = by_handle[product.handle]
                if all(candidate["id"] != known["id"] for known in candidates):
                    candidates.append(candidate)

            if not candidates:
                inserts.append(data)
                continue

            target = next(
                (
                    row
                    for row in candidates
                    if product.external_id and row["external_id"] == product.external_id
                ),
                candidates[0],
            )
            dupes = {row["id"] for row in candidates if row["id"] != target["id"]}
            if dupes:
                seen = [row["first_seen_at"] for row in candidates if row["first_seen_at"]]
                data["first_seen_at"] = min(seen) if seen else now
                duplicate_ids |= dupes

            # A feed that maps two products onto one stored row, or onto a row
            # another product is about to absorb, cannot be reordered safely in
            # a batch. Those chunks fall back to the sequential path.
            if target["id"] in claimed or target["id"] in duplicate_ids:
                self._upsert_products_sequentially(connection, products, rows, store_id)
                return

            claimed.add(target["id"])
            updates.append({**data, "existing_id": target["id"]})

        if duplicate_ids & claimed:
            self._upsert_products_sequentially(connection, products, rows, store_id)
            return

        if duplicate_ids:
            connection.execute(
                "DELETE FROM store_products WHERE id = ANY(%s)",
                (list(duplicate_ids),),
            )
        if updates:
            connection.cursor().executemany(self._PRODUCT_UPDATE_SQL, updates)
        if inserts:
            connection.cursor().executemany(self._PRODUCT_INSERT_SQL, inserts)

    def _upsert_products_sequentially(
        self,
        connection: psycopg.Connection[Any],
        products: Sequence[ProductRecord],
        rows: Sequence[dict[str, Any]],
        store_id: int,
    ) -> None:
        """Row-at-a-time fallback for chunks with colliding product identities."""
        for product, data in zip(products, rows, strict=True):
            identity_clauses: list[str] = []
            if product.external_id:
                identity_clauses.append("external_id = %(external_id)s")
            if product.handle:
                identity_clauses.append("handle = %(handle)s")
            if not identity_clauses:
                continue
            existing_rows = connection.execute(
                f"""
                SELECT id, external_id, handle, first_seen_at FROM store_products
                WHERE ecommerce_store_id = %(ecommerce_store_id)s
                  AND ({" OR ".join(identity_clauses)})
                ORDER BY id
                FOR UPDATE
                """,
                data,
            ).fetchall()
            if not existing_rows:
                connection.execute(self._PRODUCT_INSERT_SQL, data)
                continue

            existing = next(
                (
                    row
                    for row in existing_rows
                    if product.external_id and row["external_id"] == product.external_id
                ),
                existing_rows[0],
            )
            duplicate_ids = [row["id"] for row in existing_rows if row["id"] != existing["id"]]
            if duplicate_ids:
                seen = [row["first_seen_at"] for row in existing_rows if row["first_seen_at"]]
                if seen:
                    data["first_seen_at"] = min(seen)
                connection.execute(
                    "DELETE FROM store_products WHERE id = ANY(%s)",
                    (duplicate_ids,),
                )
            connection.execute(
                self._PRODUCT_UPDATE_SQL,
                {**data, "existing_id": existing["id"]},
            )

    def _product_params(
        self,
        store_id: int,
        product: ProductRecord,
        now: datetime,
    ) -> dict[str, Any]:
        data = {"ecommerce_store_id": store_id, **product.model_dump(mode="json")}
        for field in ("tags", "images", "variants", "source_payload"):
            data[field] = Jsonb(data[field])
        data.update(
            {
                "last_seen_at": now,
                "first_seen_at": now,
                "created_at": now,
                "updated_at": now,
            }
        )
        return data

    _PRODUCT_UPDATE_SQL = """
        UPDATE store_products SET
            external_id = %(external_id)s, handle = %(handle)s,
            title = %(title)s, description = %(description)s,
            vendor = %(vendor)s, product_type = %(product_type)s,
            status = %(status)s, currency_code = %(currency_code)s,
            price_cents = %(price_cents)s,
            compare_at_price_cents = %(compare_at_price_cents)s,
            minimum_variant_price_cents = %(minimum_variant_price_cents)s,
            maximum_variant_price_cents = %(maximum_variant_price_cents)s,
            variant_count = %(variant_count)s, is_available = %(is_available)s,
            inventory_quantity = %(inventory_quantity)s,
            product_url = %(product_url)s,
            primary_image_url = %(primary_image_url)s, tags = %(tags)s,
            images = %(images)s, variants = %(variants)s,
            source_payload = %(source_payload)s,
            published_at = %(published_at)s,
            source_created_at = %(source_created_at)s,
            source_updated_at = %(source_updated_at)s,
            last_seen_at = %(last_seen_at)s,
            first_seen_at = LEAST(first_seen_at, %(first_seen_at)s),
            updated_at = %(updated_at)s
        WHERE id = %(existing_id)s
        """

    _PRODUCT_INSERT_SQL = """
        INSERT INTO store_products (
            ecommerce_store_id, external_id, handle, title, description, vendor,
            product_type, status, currency_code, price_cents, compare_at_price_cents,
            minimum_variant_price_cents, maximum_variant_price_cents, variant_count,
            is_available, inventory_quantity, product_url, primary_image_url, tags,
            images, variants, source_payload, published_at, source_created_at,
            source_updated_at, last_seen_at, first_seen_at, created_at, updated_at
        ) VALUES (
            %(ecommerce_store_id)s, %(external_id)s, %(handle)s, %(title)s,
            %(description)s, %(vendor)s, %(product_type)s, %(status)s,
            %(currency_code)s, %(price_cents)s, %(compare_at_price_cents)s,
            %(minimum_variant_price_cents)s, %(maximum_variant_price_cents)s,
            %(variant_count)s, %(is_available)s, %(inventory_quantity)s,
            %(product_url)s, %(primary_image_url)s, %(tags)s, %(images)s,
            %(variants)s, %(source_payload)s, %(published_at)s, %(source_created_at)s,
            %(source_updated_at)s, %(last_seen_at)s, %(first_seen_at)s,
            %(created_at)s, %(updated_at)s
        )
        """


def _website_params(website: WebsiteRecord, now: datetime, recrawl: timedelta) -> dict[str, Any]:
    data = website.model_dump(mode="json")
    for field in ("technologies", "social_links", "source_payload"):
        data[field] = Jsonb(data[field])
    data.update(
        {
            "last_crawled_at": now,
            "next_crawl_at": now + recrawl,
            "created_at": now,
            "updated_at": now,
        }
    )
    return data
