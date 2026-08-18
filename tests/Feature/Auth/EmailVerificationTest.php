<?php

namespace Tests\Feature\Auth;

use App\Models\Plan;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlan('free', 50);
        config(['app.frontend_url' => 'https://app.example.test']);
    }

    public function test_registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email_verified', false);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertNull($user->email_verified_at);
    }

    public function test_verification_link_points_at_the_signed_api_route(): void
    {
        Notification::fake();
        $user = $this->createUser('link@example.com');

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // Must be our signed API route, not a web route, and must carry the
            // signature the `signed` middleware validates.
            return str_contains((string) $url, '/api/v1/auth/email/verify/'.$user->getKey())
                && str_contains((string) $url, 'signature=')
                && str_contains((string) $url, 'expires=');
        });
    }

    public function test_valid_link_verifies_and_redirects_to_the_spa(): void
    {
        $user = $this->createUser('verify@example.com');
        $this->assertFalse($user->hasVerifiedEmail());

        $this->get($this->verificationUrl($user))
            ->assertRedirect('https://app.example.test/email-verified?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'email_verified',
        ]);
    }

    public function test_reusing_a_valid_link_reports_already_verified(): void
    {
        $user = $this->createUser('twice@example.com');
        $url = $this->verificationUrl($user);

        $this->get($url)->assertRedirect('https://app.example.test/email-verified?status=verified');
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->get($url)->assertRedirect('https://app.example.test/email-verified?status=already-verified');

        // The original timestamp must not be overwritten.
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    /**
     * The signature is what makes this route safe to expose unauthenticated.
     */
    public function test_tampered_and_unsigned_links_are_rejected(): void
    {
        $user = $this->createUser('tamper@example.com');
        $url = $this->verificationUrl($user);

        // Signature stripped entirely.
        $this->get('/api/v1/auth/email/verify/'.$user->getKey().'/'.sha1($user->email))
            ->assertForbidden();

        // Signature present but the payload was altered.
        $this->get(str_replace('/'.sha1($user->email), '/'.sha1('someone-else@example.com'), $url))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_is_rejected(): void
    {
        $user = $this->createUser('expired@example.com');

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * A correctly signed link whose hash no longer matches the current address
     * must not verify — this is what invalidates old links after an email change.
     */
    public function test_link_issued_for_a_previous_address_is_rejected(): void
    {
        $user = $this->createUser('old@example.com');
        $url = $this->verificationUrl($user);

        $user->forceFill(['email' => 'changed@example.com'])->save();

        $this->get($url)->assertRedirect('https://app.example.test/email-verified?status=invalid');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_for_an_unknown_user_redirects_as_invalid(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => 999999, 'hash' => sha1('ghost@example.com')]
        );

        $this->get($url)->assertRedirect('https://app.example.test/email-verified?status=invalid');
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();
    }

    public function test_resend_sends_a_fresh_link(): void
    {
        Notification::fake();
        $user = $this->createUser('resend@example.com');
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('message', 'Verification email sent.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('user_activities', [
            'user_id' => $user->id,
            'type' => 'email_verification_sent',
        ]);
    }

    public function test_resend_is_a_no_op_when_already_verified(): void
    {
        Notification::fake();
        $user = $this->createUser('done@example.com');
        $user->markEmailAsVerified();
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('data.sent', false)
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('message', 'This email address is already verified.');

        Notification::assertNothingSent();
    }

    public function test_verification_routes_carry_the_expected_middleware(): void
    {
        $verify = Route::getRoutes()->match(
            Request::create('/api/v1/auth/email/verify/1/abc', 'GET')
        )->gatherMiddleware();

        // The signature is the only thing guarding this route.
        $this->assertContains('signed', $verify);
        $this->assertNotContains('auth:sanctum', $verify);

        $resend = Route::getRoutes()->match(
            Request::create('/api/v1/auth/email/resend', 'POST')
        )->gatherMiddleware();

        $this->assertContains('auth:sanctum', $resend);
        $this->assertContains('throttle:email-verification', $resend);
    }

    /**
     * The GET wildcards for OAuth must not swallow the verification route.
     */
    public function test_oauth_provider_wildcard_does_not_shadow_the_verify_route(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/auth/email/verify/1/abc', 'GET')
        );

        $this->assertSame('verification.verify', $route->getName());
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Verify User',
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
