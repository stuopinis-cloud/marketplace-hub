<?php

namespace App\Providers;

use App\Models\AutomationSchedule;
use App\Observers\AutomationScheduleObserver;
use App\Services\Deployment\MarketplaceStorageBootstrap;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ShopifyClient::class, fn (): ShopifyClient => ShopifyClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AutomationSchedule::observe(AutomationScheduleObserver::class);

        app(MarketplaceStorageBootstrap::class)->ensureDirectoriesExist();
    }
}
