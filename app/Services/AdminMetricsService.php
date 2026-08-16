<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Enums\PaymentStatus;
use App\Models\EcommerceStore;
use App\Models\PaymentOrder;
use App\Models\StoreProduct;
use App\Models\StoreScanRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminMetricsService
{
    /**
     * Dashboard summary counters.
     *
     * Conditional aggregates use CASE WHEN rather than the terser
     * `count(*) filter (...)`. Both drivers support FILTER, but the suite runs on
     * SQLite and production on PostgreSQL, and this file is not worth another
     * divergence between the two.
     */
    public function summary(): array
    {
        return [
            'users' => $this->users(),
            'subscriptions' => $this->subscriptions(),
            'revenue' => $this->revenue(),
            'credits' => $this->credits(),
            'leads' => $this->leads(),
        ];
    }

    private function users(): array
    {
        $cutoff = Carbon::now()->subDays(30);

        $row = User::query()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active then 1 else 0 end) as active')
            ->selectRaw('sum(case when email_verified_at is not null then 1 else 0 end) as verified')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as recent', [$cutoff])
            ->toBase()
            ->first();

        $total = (int) $row->total;
        $active = (int) $row->active;
        $verified = (int) $row->verified;

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'verified' => $verified,
            'unverified' => $total - $verified,
            'new_last_30_days' => (int) $row->recent,
        ];
    }

    private function subscriptions(): array
    {
        $activeByPlan = Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->groupBy('plans.slug')
            ->selectRaw('plans.slug as slug, count(*) as aggregate')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->slug => (int) $row->aggregate])
            ->all();

        $byStatus = Subscription::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => (int) $row->aggregate])
            ->all();

        return [
            'active_by_plan' => $activeByPlan,
            'by_status' => $byStatus,
        ];
    }

    private function revenue(): array
    {
        $cutoff = Carbon::now()->subDays(30);
        $approved = PaymentStatus::APPROVED->value;

        $row = PaymentOrder::query()
            ->selectRaw('coalesce(sum(case when status = ? then amount_usd_cents else 0 end), 0) as approved_total', [$approved])
            ->selectRaw('coalesce(sum(case when status = ? and created_at >= ? then amount_usd_cents else 0 end), 0) as approved_recent', [$approved, $cutoff])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as awaiting', [PaymentStatus::AWAITING_VERIFICATION->value])
            ->toBase()
            ->first();

        return [
            // Cents, so the client formats currency instead of trusting a float.
            'approved_total_cents' => (int) $row->approved_total,
            'approved_last_30_days_cents' => (int) $row->approved_recent,
            'orders_awaiting_verification' => (int) $row->awaiting,
        ];
    }

    private function credits(): array
    {
        $cutoff = Carbon::now()->subDays(30);
        $spend = CreditTransactionType::SPEND->value;

        $row = DB::table('credit_transactions')
            ->selectRaw('coalesce(sum(case when type = ? then amount else 0 end), 0) as spent', [$spend])
            ->selectRaw('coalesce(sum(case when type = ? then amount else 0 end), 0) as refunded', [CreditTransactionType::REFUND->value])
            ->selectRaw('coalesce(sum(case when type = ? and created_at >= ? then amount else 0 end), 0) as spent_recent', [$spend, $cutoff])
            ->first();

        return [
            // Spend rows are stored negative; report magnitude.
            'total_spent' => abs((int) $row->spent),
            'total_refunded' => (int) $row->refunded,
            'spent_last_30_days' => abs((int) $row->spent_recent),
            'outstanding_balance' => (int) User::query()->sum('credits_balance'),
        ];
    }

    private function leads(): array
    {
        $websites = Website::query()
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when crawl_status = 'completed' then 1 else 0 end) as crawled")
            ->toBase()
            ->first();

        return [
            'websites' => (int) $websites->total,
            'websites_crawled' => (int) $websites->crawled,
            'ecommerce_stores' => (int) EcommerceStore::query()->count(),
            'products' => (int) StoreProduct::query()->count(),
            'deep_scans_completed' => (int) StoreScanRequest::query()
                ->where('status', 'completed')
                ->count(),
        ];
    }

    /**
     * Operational view of the discovery pipeline.
     *
     * This is what tells an operator whether the Python workers are running at
     * all: a climbing `due_now` alongside a flat `completed` count means Celery
     * beat or the worker is down.
     */
    public function pipeline(): array
    {
        $now = Carbon::now();

        $crawlStatus = Website::query()
            ->groupBy('crawl_status')
            ->selectRaw('crawl_status, count(*) as aggregate')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->crawl_status => (int) $row->aggregate])
            ->all();

        $scanStatus = StoreScanRequest::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => (int) $row->aggregate])
            ->all();

        return [
            'websites_by_crawl_status' => $crawlStatus,
            'scans_by_status' => $scanStatus,
            'due_now' => (int) Website::query()
                ->whereIn('crawl_status', ['pending', 'failed', 'completed'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_crawl_at')->orWhere('next_crawl_at', '<=', $now);
                })
                ->count(),
            // Non-zero means a worker died mid-crawl and its lease lapsed. The
            // recovery tick should clear it within a minute; a persistent value
            // means recovery is not running.
            'stale_crawl_claims' => (int) Website::query()
                ->where('crawl_status', 'crawling')
                ->whereNotNull('claim_lease_expires_at')
                ->where('claim_lease_expires_at', '<', $now)
                ->count(),
            'stale_scan_claims' => (int) StoreScanRequest::query()
                ->where('status', 'running')
                ->whereNotNull('claim_lease_expires_at')
                ->where('claim_lease_expires_at', '<', $now)
                ->count(),
            'last_completed_crawl_at' => Website::query()
                ->where('crawl_status', 'completed')
                ->max('last_crawled_at'),
        ];
    }
}
