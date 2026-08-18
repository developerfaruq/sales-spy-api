<?php

namespace Tests\Feature\Admin;

use App\Enums\CreditTransactionType;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlan('free', 50);
        Role::findOrCreate('admin', 'api');
        Role::findOrCreate('user', 'api');
    }

    public function test_management_endpoints_require_the_admin_role(): void
    {
        $target = $this->createUser('target@example.com');
        Sanctum::actingAs($this->createUser('plain@example.com')->fresh());

        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$target->id}/restore")->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$target->id}/admin")->assertForbidden();
        $this->deleteJson("/api/v1/admin/users/{$target->id}/admin")->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$target->id}/credits", ['amount' => 5, 'reason' => 'test'])
            ->assertForbidden();
        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->putJson('/api/v1/admin/settings', ['settings' => []])->assertForbidden();
    }

    /**
     * Deleting must be soft: payment and credit history reference the user row.
     */
    public function test_deleting_a_user_soft_deletes_and_revokes_access(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('doomed@example.com');
        $token = $target->createToken('device')->plainTextToken;
        $this->assertSame(1, $target->tokens()->count());

        // Real bearer tokens rather than Sanctum::actingAs, because actingAs
        // installs a mock guard for the rest of the test and would keep
        // authenticating the deleted user's request as the admin.
        $adminToken = $admin->createToken('admin-device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully');

        // Row retained so financial history stays intact.
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertNotNull($target->fresh()?->deleted_at ?? User::withTrashed()->find($target->id)->deleted_at);
        $this->assertSame(0, User::withTrashed()->find($target->id)->tokens()->count());
        $this->assertFalse((bool) User::withTrashed()->find($target->id)->is_active);

        // The soft-delete scope hides them from normal queries.
        $this->assertNull(User::find($target->id));

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_user_deleted',
        ]);

        // The revoked token must no longer authenticate. forgetGuards() is
        // required because the container's auth guard caches the user it resolved
        // during the delete request, and the same application instance serves
        // every request inside one test.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/user/credits')
            ->assertUnauthorized();
    }

    public function test_deleted_user_cannot_log_in(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('gone@example.com');
        Sanctum::actingAs($admin->fresh());
        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'gone@example.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    /**
     * The email stays reserved, so a deleted account cannot be silently recreated.
     */
    public function test_deleted_users_email_cannot_be_reregistered(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('reserved@example.com');
        Sanctum::actingAs($admin->fresh());
        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Impostor',
            'email' => 'reserved@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->deleteJson("/api/v1/admin/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertNotNull(User::find($admin->id));
    }

    /**
     * Losing the last admin would lock the organisation out of its own panel.
     */
    public function test_last_admin_cannot_be_deleted_or_demoted(): void
    {
        $admin = $this->createAdmin();
        $second = $this->createAdmin('second-admin@example.com');
        Sanctum::actingAs($second->fresh());

        // Two admins exist, so deleting one is allowed.
        $this->deleteJson("/api/v1/admin/users/{$admin->id}")->assertOk();

        // Now only $second remains; they cannot demote themselves either.
        $this->deleteJson("/api/v1/admin/users/{$second->id}/admin")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You cannot demote your own account.');

        $this->assertTrue($second->fresh()->hasRole('admin'));
    }

    public function test_last_remaining_admin_cannot_be_demoted_by_another_path(): void
    {
        $onlyAdmin = $this->createAdmin();
        // A second admin acts, then is removed, leaving one.
        $helper = $this->createAdmin('helper@example.com');
        Sanctum::actingAs($onlyAdmin->fresh());
        $this->deleteJson("/api/v1/admin/users/{$helper->id}/admin")->assertOk();

        // Only $onlyAdmin holds the role now. Promote a third to act.
        $third = $this->createAdmin('third@example.com');
        Sanctum::actingAs($third->fresh());
        $this->deleteJson("/api/v1/admin/users/{$onlyAdmin->id}/admin")->assertOk();

        // $third is the last one standing.
        Sanctum::actingAs($third->fresh());
        $this->deleteJson("/api/v1/admin/users/{$third->id}/admin")
            ->assertStatus(409);
    }

    public function test_restore_brings_a_user_back_deactivated(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('returning@example.com');
        Sanctum::actingAs($admin->fresh());
        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->postJson("/api/v1/admin/users/{$target->id}/restore")
            ->assertOk()
            // Restoring must not silently hand access back.
            ->assertJsonPath('data.is_active', false);

        $restored = User::find($target->id);
        $this->assertNotNull($restored);
        $this->assertFalse((bool) $restored->is_active);

        // Still cannot log in until reactivated.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'returning@example.com',
            'password' => 'password123',
        ])->assertUnauthorized();

        // Reactivating restores login.
        Sanctum::actingAs($admin->fresh());
        $this->patchJson("/api/v1/admin/users/{$target->id}/toggle-status")->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'returning@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_restoring_a_live_user_returns_not_found(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('alive@example.com');
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/restore")
            ->assertNotFound()
            ->assertJsonPath('message', 'Deleted user not found.');
    }

    public function test_admin_role_can_be_granted_and_revoked(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('promote@example.com');
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/admin")
            ->assertOk()
            ->assertJsonPath('message', 'Admin role granted successfully');
        $this->assertTrue($target->fresh()->hasRole('admin'));
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_role_granted',
        ]);

        // The promoted user can now reach admin endpoints.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/v1/admin/metrics')->assertOk();

        Sanctum::actingAs($admin->fresh());
        $this->deleteJson("/api/v1/admin/users/{$target->id}/admin")
            ->assertOk()
            ->assertJsonPath('message', 'Admin role revoked successfully');
        $this->assertFalse($target->fresh()->hasRole('admin'));

        // Revoking signs the demoted user out.
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_granting_admin_is_idempotent(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('twice@example.com');
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/admin")->assertOk();
        $this->postJson("/api/v1/admin/users/{$target->id}/admin")->assertOk();

        $this->assertSame(1, $target->fresh()->roles()->where('name', 'admin')->count());
    }

    public function test_deactivated_user_cannot_be_promoted(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('suspended@example.com');
        $target->update(['is_active' => false]);
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/admin")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A deactivated user cannot be promoted to admin.');

        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_credits_are_granted_through_the_ledger(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('needs-credits@example.com');
        $before = $target->credits_balance;
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/credits", [
            'amount' => 100,
            'reason' => 'Goodwill for the failed scan',
        ])
            ->assertOk()
            ->assertJsonPath('data.amount', 100)
            ->assertJsonPath('data.credits_balance', $before + 100);

        // Balance and ledger must agree, which a direct column write would break.
        $this->assertSame($before + 100, $target->fresh()->credits_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $target->id,
            'type' => CreditTransactionType::ADMIN_ADJUSTMENT->value,
            'amount' => 100,
            'balance_before' => $before,
            'balance_after' => $before + 100,
        ]);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_credits_adjusted',
        ]);
    }

    public function test_credit_adjustment_requires_a_positive_amount_and_reason(): void
    {
        $admin = $this->createAdmin();
        $target = $this->createUser('validate@example.com');
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/users/{$target->id}/credits", ['amount' => 0, 'reason' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Amount must be a positive number of credits to grant.');

        $this->postJson("/api/v1/admin/users/{$target->id}/credits", ['amount' => -50, 'reason' => 'claw back'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/admin/users/{$target->id}/credits", ['amount' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'A reason is required so the adjustment is auditable.');
    }

    public function test_unknown_user_returns_not_found(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->deleteJson('/api/v1/admin/users/999999')->assertNotFound();
        $this->postJson('/api/v1/admin/users/999999/admin')->assertNotFound();
        $this->postJson('/api/v1/admin/users/999999/credits', ['amount' => 5, 'reason' => 'test'])
            ->assertNotFound();
    }

    public function test_settings_are_listed_with_cast_values(): void
    {
        Setting::set('credit_cost_deep_scan', 10, 'integer');
        Setting::set('crypto_wallet_address', 'TOriginalAddress', 'string');
        Sanctum::actingAs($this->createAdmin()->fresh());

        $response = $this->getJson('/api/v1/admin/settings')->assertOk();
        $rows = collect($response->json('data'))->keyBy('key');

        // Integer settings must come back as integers, not the raw string column.
        $this->assertSame(10, $rows['credit_cost_deep_scan']['value']);
        $this->assertSame('integer', $rows['credit_cost_deep_scan']['type']);
        $this->assertSame('TOriginalAddress', $rows['crypto_wallet_address']['value']);
    }

    /**
     * The docs require the wallet address to be changeable without a deploy, and
     * the cached value must not survive the change.
     */
    public function test_updating_a_setting_takes_effect_immediately(): void
    {
        Setting::set('crypto_wallet_address', 'TOldAddress', 'string');
        // Warm the cache so a stale read would be visible.
        $this->assertSame('TOldAddress', Setting::get('crypto_wallet_address'));
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin->fresh());

        $this->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'crypto_wallet_address', 'value' => 'TNewAddress']],
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', ['crypto_wallet_address']);

        $this->assertSame('TNewAddress', Setting::get('crypto_wallet_address'));
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_settings_updated',
        ]);
    }

    public function test_credit_cost_change_is_reflected_in_charging(): void
    {
        Setting::set('credit_cost_deep_scan', 10, 'integer');
        Sanctum::actingAs($this->createAdmin()->fresh());

        $this->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'credit_cost_deep_scan', 'value' => 25]],
        ])->assertOk();

        Cache::flush();
        $this->assertSame(25, app(CreditService::class)->getCost('deep_scan'));
    }

    public function test_setting_values_are_validated_against_their_type(): void
    {
        Setting::set('credit_cost_deep_scan', 10, 'integer');
        Sanctum::actingAs($this->createAdmin()->fresh());

        $response = $this->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'credit_cost_deep_scan', 'value' => 'not-a-number']],
        ])->assertUnprocessable();

        $this->assertSame(
            'The value for credit_cost_deep_scan must be of type integer.',
            $response->json('errors')['settings.0.value'][0]
        );

        // The stored value must be untouched.
        $this->assertSame(10, Setting::get('credit_cost_deep_scan'));
    }

    /**
     * An unknown key would be inert while appearing to succeed, because the app
     * reads specific keys by name.
     */
    public function test_unknown_setting_keys_are_rejected(): void
    {
        Sanctum::actingAs($this->createAdmin()->fresh());

        $response = $this->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'totally_made_up_key', 'value' => 'x']],
        ])->assertUnprocessable();

        $this->assertSame(
            'Unknown setting key. Settings must already exist to be updated.',
            $response->json('errors')['settings.0.key'][0]
        );
    }

    private function createAdmin(string $email = 'admin@example.com'): User
    {
        $admin = $this->createUser($email);
        $admin->assignRole('admin');

        return $admin;
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Managed User',
            'email' => $email,
            'password' => 'password123',
        ]);

        app(SubscriptionService::class)->assignFreePlan($user);

        return $user->fresh();
    }

    private function createPlan(string $slug, int $quota): Plan
    {
        return Plan::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => $quota,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
