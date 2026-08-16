<?php

namespace Tests\Feature\Notification;

use App\Enums\CommercePlatform;
use App\Enums\CrawlStatus;
use App\Enums\CreditTransactionType;
use App\Enums\NotificationType;
use App\Enums\StoreScanStatus;
use App\Models\CreditTransaction;
use App\Models\EcommerceStore;
use App\Models\InAppNotification;
use App\Models\Plan;
use App\Models\StoreScanRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAndScanStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_listed_filtered_and_marked_read(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $first = $this->createNotification($user, NotificationType::SCAN_COMPLETED, 'scan-1');
        $this->createNotification($user, NotificationType::SCAN_FAILED, 'scan-2');
        $this->createNotification($other, NotificationType::SCAN_COMPLETED, 'other-scan');
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/user/notifications?unread=true&type=scan_completed')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.unread_count', 2);

        $this->patchJson("/api/v1/user/notifications/{$first->id}/read")
            ->assertOk();

        $this->getJson('/api/v1/user/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->postJson('/api/v1/user/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 1);
    }

    public function test_notification_and_scan_status_are_user_scoped_and_internal_fields_are_hidden(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $scan = $this->createScan($user, StoreScanStatus::FAILED);
        $notification = $this->createNotification($user, NotificationType::SCAN_FAILED, $scan->id);
        Sanctum::actingAs($other->fresh());

        $this->getJson("/api/v1/user/scans/{$scan->id}")
            ->assertNotFound();
        $this->patchJson("/api/v1/user/notifications/{$notification->id}/read")
            ->assertNotFound();

        Sanctum::actingAs($user->fresh());
        $this->getJson("/api/v1/user/scans/{$scan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonMissingPath('data.claim_token')
            ->assertJsonMissingPath('data.error_message');
    }

    public function test_scan_status_reports_refunds_only_when_credits_were_returned(): void
    {
        $user = $this->createUser();
        $scan = $this->createScan($user, StoreScanStatus::FAILED);
        Sanctum::actingAs($user->fresh());

        // A zero-amount refund is written when the charge belongs to an earlier
        // credit period. The API must not claim credits were returned, and it
        // must read the reason the worker recorded rather than inferring it from
        // the amount, which cannot distinguish this from an unlimited plan.
        $stalePeriodRefund = CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransactionType::REFUND,
            'amount' => 0,
            'balance_before' => $user->credits_balance,
            'balance_after' => $user->credits_balance,
            'description' => 'Stale period refund',
            'idempotency_key' => "scan-refund-stale:{$scan->id}",
            'metadata' => ['credit_period_current' => false, 'unlimited' => false],
        ]);
        $scan->update(['refund_transaction_id' => $stalePeriodRefund->id]);

        $this->getJson("/api/v1/user/scans/{$scan->id}")
            ->assertOk()
            ->assertJsonPath('data.credits_refunded', false)
            ->assertJsonPath('data.refunded_credits', 0)
            ->assertJsonPath('data.error', 'The scan could not be completed. No credits were refunded because the original charge belongs to an earlier credit period.');

        // An unlimited plan also refunds zero, but for an unrelated reason. The
        // period explanation would be false here.
        $unlimitedRefund = CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransactionType::REFUND,
            'amount' => 0,
            'balance_before' => $user->credits_balance,
            'balance_after' => $user->credits_balance,
            'description' => 'Unlimited plan refund',
            'idempotency_key' => "scan-refund-unlimited:{$scan->id}",
            'metadata' => ['credit_period_current' => true, 'unlimited' => true],
        ]);
        $scan->update(['refund_transaction_id' => $unlimitedRefund->id]);

        $this->getJson("/api/v1/user/scans/{$scan->id}")
            ->assertOk()
            ->assertJsonPath('data.credits_refunded', false)
            ->assertJsonPath('data.refunded_credits', 0)
            ->assertJsonPath('data.error', 'The scan could not be completed. Your plan has unlimited credits, so there was nothing to refund.');

        $realRefund = CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransactionType::REFUND,
            'amount' => 10,
            'balance_before' => $user->credits_balance,
            'balance_after' => $user->credits_balance + 10,
            'description' => 'Scan refund',
            'idempotency_key' => "scan-refund-real:{$scan->id}",
        ]);
        $scan->update(['refund_transaction_id' => $realRefund->id]);

        $this->getJson("/api/v1/user/scans/{$scan->id}")
            ->assertOk()
            ->assertJsonPath('data.credits_refunded', true)
            ->assertJsonPath('data.refunded_credits', 10)
            ->assertJsonPath('data.error', 'The scan could not be completed and your scan credits were refunded.');
    }

    private function createUser(): User
    {
        $plan = Plan::firstOrCreate([
            'slug' => 'free',
        ], [
            'name' => 'Free',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => 50,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $user = User::create([
            'name' => 'Notification User',
            'email' => uniqid('notification-', true).'@example.com',
            'password' => 'password123',
        ]);
        app(SubscriptionService::class)->assignFreePlan($user);

        return $user->fresh();
    }

    private function createNotification(User $user, NotificationType $type, string $reference): InAppNotification
    {
        return InAppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => 'Test notification',
            'message' => 'Test message',
            'reference_type' => StoreScanRequest::class,
            'reference_id' => $reference,
            'data' => ['domain' => 'example.com'],
        ]);
    }

    private function createScan(User $user, StoreScanStatus $status): StoreScanRequest
    {
        $website = Website::create([
            'domain' => uniqid('scan-', true).'.example.com',
            'canonical_url' => 'https://example.com',
            'website_status' => 'active',
            'is_ecommerce' => true,
            'source' => 'test',
            'crawl_status' => 'completed',
            'last_seen_at' => now(),
        ]);
        $store = EcommerceStore::create([
            'website_id' => $website->id,
            'platform' => CommercePlatform::SHOPIFY,
            'crawl_status' => CrawlStatus::COMPLETED,
            'is_active' => true,
        ]);

        return StoreScanRequest::create([
            'user_id' => $user->id,
            'ecommerce_store_id' => $store->id,
            'idempotency_key' => uniqid('scan-key-', true),
            'status' => $status,
            'requested_at' => now(),
        ]);
    }
}
