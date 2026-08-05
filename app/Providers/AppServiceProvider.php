<?php

namespace App\Providers;

use App\Services\ActivityService;
use App\Services\AuthService;
use App\Services\CloudinaryService;
use App\Services\CreditService;
use App\Services\PaymentService;
use App\Services\ProfileService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
