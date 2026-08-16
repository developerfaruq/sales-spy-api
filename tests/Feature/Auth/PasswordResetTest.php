<?php

namespace Tests\Feature\Auth;

use App\Models\Plan;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlan('free', 50);
    }

    public function test_reset_link_is_emailed_and_carries_the_frontend_url(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.example.test']);
        $user = $this->createUser('reset@example.com');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If that email is registered, a password reset link is on its way.');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // The link must point at the SPA, not at this API, and must carry
            // both the token and the email the broker validates it against.
            return str_starts_with((string) $url, 'https://app.example.test/reset-password?')
                && str_contains((string) $url, 'token='.$notification->token)
                && str_contains((string) $url, 'email='.urlencode($user->email));
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset@example.com']);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'password_reset_requested',
        ]);
    }

    /**
     * The endpoint must not become an account-existence oracle.
     *
     * Comparing only the response bodies would be tautological, because the
     * controller has a single hardcoded success return. These assert the things
     * that can actually diverge: status code, whether a token row was written,
     * and whether a notification was sent.
     */
    public function test_unknown_email_returns_the_same_response_as_a_known_one(): void
    {
        Notification::fake();
        $user = $this->createUser('known@example.com');

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());

        // Only the real account gets a token and an email.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'known@example.com']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.com']);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    /**
     * A broken mail transport must not become an enumeration oracle.
     *
     * Mail is sent synchronously inside the broker, so an uncaught
     * TransportException surfaces as a 500 — and only ever for a registered
     * address, since unknown addresses never reach the send.
     */
    public function test_mail_transport_failure_does_not_leak_account_existence(): void
    {
        $this->createUser('exists@example.com');

        Mail::shouldReceive('mailer')
            ->andThrow(new TransportException('Connection refused'));
        Mail::shouldReceive('send')
            ->andThrow(new TransportException('Connection refused'));

        $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'exists@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        $this->assertSame(200, $existing->getStatusCode());
        $this->assertSame(200, $unknown->getStatusCode());
        $this->assertSame($existing->json(), $unknown->json());
    }

    /**
     * The broker suppresses repeat sends per address, and the client is told so
     * rather than being left waiting for an email that will never arrive.
     */
    public function test_response_advertises_a_constant_retry_after(): void
    {
        Notification::fake();
        $this->createUser('retry@example.com');

        $expected = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.throttle');

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'retry@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com']);

        // Constant, so it cannot itself reveal whether the address exists.
        $known->assertOk()->assertJsonPath('data.retry_after', $expected);
        $unknown->assertOk()->assertJsonPath('data.retry_after', $expected);
    }

    public function test_password_is_reset_and_every_session_is_revoked(): void
    {
        $user = $this->createUser('revoke@example.com');
        $token = Password::broker()->createToken($user);

        // Three live sessions, one of which is the caller's own bearer token.
        // All three must be gone, unlike changePassword which keeps the caller's.
        $callerToken = $user->createToken('caller');
        $callerTokenId = $callerToken->accessToken->getKey();
        $user->createToken('device-a');
        $user->createToken('device-b');
        $this->assertSame(3, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$callerToken->plainTextToken)
            ->postJson('/api/v1/auth/reset-password', [
                'token' => $token,
                'email' => 'revoke@example.com',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password reset successfully. Please log in with your new password.');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        // Named explicitly so "revoke all except current" cannot pass this test.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $callerTokenId]);

        // Token is single-use.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'revoke@example.com']);

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'password_reset_completed',
        ]);

        // The new password must actually work, proving it was not double-hashed.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'revoke@example.com',
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    public function test_reused_token_is_rejected(): void
    {
        $user = $this->createUser('reuse@example.com');
        $token = Password::broker()->createToken($user);

        $payload = [
            'token' => $token,
            'email' => 'reuse@example.com',
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            ...$payload,
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');

        $user->refresh();
        $this->assertTrue(Hash::check('first-new-password', $user->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->createUser('expired@example.com');
        $token = Password::broker()->createToken($user);

        // config('auth.passwords.users.expire') is 60 minutes.
        DB::table('password_reset_tokens')
            ->where('email', 'expired@example.com')
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'expired@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');

        $this->assertFalse(Hash::check('brand-new-password', $user->fresh()->password));
    }

    /**
     * A bad token and an unknown email must be indistinguishable.
     *
     * The bodies are identical by construction (one error return), so these also
     * assert that neither request mutated any password.
     */
    public function test_invalid_token_and_unknown_email_share_one_message(): void
    {
        $user = $this->createUser('real@example.com');

        $badToken = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'real@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'ghost@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertSame(400, $badToken->getStatusCode());
        $this->assertSame($badToken->getStatusCode(), $unknownEmail->getStatusCode());
        $this->assertSame($badToken->json(), $unknownEmail->json());

        // Neither attempt may change the real account's password.
        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    /**
     * OAuth-only accounts have password = null. They are deliberately allowed to
     * set a first password; refusing would leak which addresses are
     * Google/GitHub-backed.
     */
    public function test_oauth_only_user_can_set_a_first_password(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'OAuth User',
            'email' => 'oauth@example.com',
            'password' => null,
            'email_verified_at' => now(),
        ]);
        app(SubscriptionService::class)->assignFreePlan($user);
        $this->assertNull($user->password);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'oauth@example.com'])
            ->assertOk();
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'oauth@example.com',
            'password' => 'first-password123',
            'password_confirmation' => 'first-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('first-password123', $user->fresh()->password));

        // Password login must now work for an account that previously had none.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'oauth@example.com',
            'password' => 'first-password123',
        ])->assertOk();
    }

    public function test_validation_rejects_short_and_unconfirmed_passwords(): void
    {
        $user = $this->createUser('weak@example.com');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.password.0', 'Password must be at least 8 characters.');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'weak@example.com',
            'password' => 'long-enough-password',
            'password_confirmation' => 'does-not-match',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Password confirmation does not match.');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed');
    }

    /**
     * Password reset must not share login's bucket, and must be limited by
     * address as well as by IP.
     */
    public function test_password_reset_routes_use_the_dedicated_limiter(): void
    {
        foreach (['forgot-password', 'reset-password'] as $path) {
            $route = Route::getRoutes()->match(
                Request::create("/api/v1/auth/{$path}", 'POST')
            );

            $middleware = $route->gatherMiddleware();
            $this->assertContains('throttle:password-reset', $middleware);
            $this->assertNotContains('throttle:20,1', $middleware);
        }

        // A per-IP limit alone lets one caller lock everyone out, so the named
        // limiter must also key on the submitted address.
        $limiter = RateLimiter::limiter('password-reset');
        $this->assertNotNull($limiter, 'The password-reset limiter is not registered.');

        $limits = $limiter(Request::create('/api/v1/auth/forgot-password', 'POST', [
            'email' => 'Someone@Example.com',
        ]));

        $keys = collect($limits)->map(fn ($limit) => $limit->key)->all();
        $this->assertTrue(
            collect($keys)->contains(fn (string $key): bool => str_contains($key, 'ip:')),
            'Expected a per-IP limit.'
        );
        $this->assertTrue(
            collect($keys)->contains(fn (string $key): bool => str_contains($key, 'someone@example.com')),
            'Expected a per-email limit keyed on the lowercased address.'
        );
    }

    public function test_password_reset_routes_are_public(): void
    {
        // Assert the middleware directly. A request that never authenticates
        // would pass assertGuest() regardless of what guards the route.
        foreach (['forgot-password', 'reset-password'] as $path) {
            $middleware = Route::getRoutes()->match(
                Request::create("/api/v1/auth/{$path}", 'POST')
            )->gatherMiddleware();

            $this->assertNotContains('auth:sanctum', $middleware);
            $this->assertNotContains('active', $middleware);
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'anyone@example.com'])
            ->assertOk();
    }

    /**
     * A reset must not be usable to hijack a different account.
     */
    public function test_token_issued_for_one_user_cannot_reset_another(): void
    {
        $victim = $this->createUser('victim@example.com');
        $attacker = $this->createUser('attacker@example.com');
        $attackerToken = Password::broker()->createToken($attacker);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $attackerToken,
            'email' => 'victim@example.com',
            'password' => 'hijacked-password',
            'password_confirmation' => 'hijacked-password',
        ])->assertStatus(400);

        $this->assertFalse(Hash::check('hijacked-password', $victim->fresh()->password));
        $this->assertTrue(Hash::check('password123', $victim->fresh()->password));
    }

    /**
     * Sanctum::actingAs installs a mock and writes no token row, so the caller's
     * session is issued as a real bearer token here instead.
     */
    public function test_reset_revokes_the_callers_own_token(): void
    {
        $user = $this->createUser('midsession@example.com');
        $callerToken = $user->createToken('caller');
        $callerTokenId = $callerToken->accessToken->getKey();
        $user->createToken('other-device');
        $this->assertSame(2, $user->tokens()->count());

        $token = Password::broker()->createToken($user);

        $this->withHeader('Authorization', 'Bearer '.$callerToken->plainTextToken)
            ->postJson('/api/v1/auth/reset-password', [
                'token' => $token,
                'email' => 'midsession@example.com',
                'password' => 'rotated-password',
                'password_confirmation' => 'rotated-password',
            ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $callerTokenId]);
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Reset User',
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
