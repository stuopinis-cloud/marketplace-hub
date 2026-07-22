<?php

namespace App\Providers;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;
use App\Models\AutomationSchedule;
use App\Observers\AutomationScheduleObserver;
use App\Services\Deployment\MarketplaceStorageBootstrap;
use App\Services\Marketplace\Translations\NoopMarketplaceTranslator;
use App\Services\Marketplace\Translations\OpenAiMarketplaceTranslator;
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

        $this->app->bind(MarketplaceTranslatorInterface::class, function () {
            $provider = (string) config('marketplace.translations.provider', 'openai');

            return match ($provider) {
                'manual', 'noop' => new NoopMarketplaceTranslator,
                default => new OpenAiMarketplaceTranslator,
            };
        });
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
