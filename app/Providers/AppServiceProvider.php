<?php

namespace App\Providers;

use App\Services\ActivityService;
use App\Services\AdminLeadService;
use App\Services\AdminMetricsService;
use App\Services\AuthService;
use App\Services\CloudinaryService;
use App\Services\CreditService;
use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;
use App\Services\PaymentService;
use App\Services\ProfileService;
use App\Services\SubscriptionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudinaryService::class, function () {
            return new CloudinaryService;
        });

        $this->app->singleton(ActivityService::class, function () {
            return new ActivityService;
        });

        $this->app->singleton(ProfileService::class, function ($app) {
            return new ProfileService(
                $app->make(CloudinaryService::class)
            );
        });

        $this->app->singleton(CreditService::class, function () {
            return new CreditService;
        });

        $this->app->singleton(SubscriptionService::class, function ($app) {
            return new SubscriptionService(
                $app->make(CreditService::class)
            );
        });

        $this->app->singleton(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(ActivityService::class),
                $app->make(SubscriptionService::class),
            );
        });
        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(SubscriptionService::class),
                $app->make(CloudinaryService::class),
                $app->make(ActivityService::class),
            );
        });

        $this->app->singleton(PasswordResetService::class, function ($app) {
            return new PasswordResetService(
                $app->make(ActivityService::class)
            );
        });

        $this->app->singleton(EmailVerificationService::class, function ($app) {
            return new EmailVerificationService(
                $app->make(ActivityService::class)
            );
        });

        $this->app->singleton(AdminMetricsService::class, function () {
            return new AdminMetricsService;
        });

        $this->app->singleton(AdminLeadService::class, function () {
            return new AdminLeadService;
        });
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        // One rule for password strength, so the reset endpoint can never become
        // a way to set a password that registration would have rejected.
        Password::defaults(fn () => Password::min(8));

        // Keyed on the address as well as the client, because a per-IP limit
        // alone lets one caller exhaust the bucket for everybody, and a
        // per-address limit alone is trivially bypassed by rotating emails.
        RateLimiter::for('email-verification', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('email-verification:ip:'.$request->ip()),
                Limit::perHour(6)->by('email-verification:user:'.($request->user()?->getKey() ?? 'guest')),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('password-reset:ip:'.$request->ip()),
                Limit::perMinute(3)->by('password-reset:email:'.$email),
            ];
        });
    }
}
