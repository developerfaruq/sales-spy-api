<?php

namespace App\Providers;

use App\Services\CloudinaryService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

use App\Services\ActivityService;
use App\Services\ProfileService;
use App\Services\AuthService;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CloudinaryService::class, function () {
            return new CloudinaryService();
        });

        $this->app->singleton(ActivityService::class, function () {
            return new ActivityService();
        });

        $this->app->singleton(ProfileService::class, function ($app) {
            return new ProfileService(
                $app->make(CloudinaryService::class)
            );
        });
        $this->app->singleton(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(ActivityService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
