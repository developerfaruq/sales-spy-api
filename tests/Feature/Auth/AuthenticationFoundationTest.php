<?php

namespace Tests\Feature\Auth;

use App\Enums\OAuthProviderEnum;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SubscriptionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlan('free', 50);
    }

    public function test_login_returns_the_subscription_plan_and_records_activity(): void
    {
        $user = User::create([
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);
        app(SubscriptionService::class)->assignFreePlan($user);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.plan', 'free')
            ->assertJsonPath('data.user.roles.0', 'user');

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'login',
        ]);
    }

    public function test_new_oauth_user_receives_a_role_free_subscription_and_free_credits(): void
    {
        $socialiteUser = (new SocialiteUser)
            ->map([
                'id' => 'google-123',
                'name' => 'OAuth User',
                'email' => 'oauth@example.com',
                'avatar' => 'https://example.com/avatar.png',
            ])
            ->setToken('oauth-token');

        $user = app(AuthService::class)->findOrCreateOAuthUser(
            $socialiteUser,
            OAuthProviderEnum::GOOGLE
        );

        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('user'));
        $this->assertSame(50, $user->credits_balance);
        $this->assertSame('free', $user->activeSubscription?->plan?->slug);
        $this->assertDatabaseHas('oauth_providers', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);
    }

    public function test_inactive_oauth_user_is_rejected(): void
    {
        $user = User::create([
            'name' => 'Inactive OAuth User',
            'email' => 'inactive-oauth@example.com',
            'password' => null,
            'is_active' => false,
        ]);

        $user->oauthProviders()->create([
            'provider' => 'google',
            'provider_id' => 'inactive-google-123',
            'access_token' => 'old-token',
        ]);

        $socialiteUser = (new SocialiteUser)
            ->map([
                'id' => 'inactive-google-123',
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->setToken('new-token');

        $this->expectException(AuthenticationException::class);

        app(AuthService::class)->findOrCreateOAuthUser(
            $socialiteUser,
            OAuthProviderEnum::GOOGLE
        );
    }

    public function test_oauth_callback_returns_the_subscription_plan_and_records_activity(): void
    {
        $socialiteUser = (new SocialiteUser)
            ->map([
                'id' => 'callback-google-123',
                'name' => 'Callback User',
                'email' => 'callback@example.com',
                'avatar' => null,
            ])
            ->setToken('callback-token');

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);

        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertOk()
            ->assertJsonPath('data.user.plan', 'free')
            ->assertJsonPath('data.user.roles.0', 'user')
            ->assertJsonPath('data.user.credits_balance', 50);

        $user = User::where('email', 'callback@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'login',
        ]);
    }

    public function test_existing_oauth_user_without_a_subscription_is_repaired_on_login(): void
    {
        $user = User::create([
            'name' => 'Existing OAuth User',
            'email' => 'existing-oauth@example.com',
            'password' => null,
        ]);
        $user->oauthProviders()->create([
            'provider' => 'google',
            'provider_id' => 'existing-google-123',
            'access_token' => 'old-token',
        ]);

        $socialiteUser = (new SocialiteUser)
            ->map([
                'id' => 'existing-google-123',
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->setToken('new-token');

        $authenticatedUser = app(AuthService::class)->findOrCreateOAuthUser(
            $socialiteUser,
            OAuthProviderEnum::GOOGLE
        );

        $this->assertSame('free', $authenticatedUser->currentPlanSlug());
        $this->assertSame(50, $authenticatedUser->credits_balance);
        $this->assertSame(1, $authenticatedUser->subscriptions()->count());
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $authenticatedUser->id,
            'type' => 'subscription_grant',
            'balance_after' => 50,
        ]);
    }

    public function test_oauth_only_user_cannot_use_password_login(): void
    {
        $user = User::create([
            'name' => 'OAuth Only User',
            'email' => 'oauth-only@example.com',
            'password' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    public function test_inactive_user_cannot_use_an_existing_token(): void
    {
        $user = User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/profile')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account has been deactivated.');
    }

    public function test_deactivating_a_user_revokes_all_their_tokens(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);
        $admin->assignRole('admin');
        $admin->refresh();

        $user = User::create([
            'name' => 'Target User',
            'email' => 'target@example.com',
            'password' => 'password123',
        ]);
        $user->createToken('existing-session');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_public_auth_routes_are_rate_limited(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/auth/login', 'POST'));

        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }

    public function test_unsupported_oauth_provider_is_rejected_before_socialite_is_called(): void
    {
        $this->getJson('/api/v1/auth/unsupported/redirect')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Unsupported OAuth provider');
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
