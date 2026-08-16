<?php

namespace Tests\Feature\Lead;

use App\Enums\CrawlStatus;
use App\Enums\WebsiteStatus;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class PostgresLeadApiTest extends TestCase
{
    public function test_postgres_full_text_website_search_uses_the_production_query(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL lead API test requires the pgsql connection.');
        }

        $domain = 'pgsql-lead-api.example.com';
        $email = 'pgsql-lead-api-user@example.com';

        try {
            $user = User::create([
                'name' => 'PostgreSQL Lead User',
                'email' => $email,
                'password' => 'password123',
                'credits_balance' => 10,
                'credits_monthly_quota' => 10,
            ])->fresh();
            Website::create([
                'domain' => $domain,
                'canonical_url' => "https://{$domain}",
                'name' => 'Distinctive Quantum Fashion Store',
                'description' => 'PostgreSQL full text search verification',
                'website_status' => WebsiteStatus::ACTIVE,
                'source' => 'pgsql_api_test',
                'crawl_status' => CrawlStatus::COMPLETED,
                'last_seen_at' => now(),
            ]);
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/websites?q=Quantum Fashion')
                ->assertOk()
                ->assertJsonPath('data.0.domain', $domain)
                ->assertJsonPath('meta.total', 1);
        } finally {
            User::where('email', $email)->delete();
            Website::where('domain', $domain)->delete();
        }
    }
}
