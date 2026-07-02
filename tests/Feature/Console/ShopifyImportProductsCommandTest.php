<?php

namespace Tests\Feature\Console;

use App\Jobs\ImportShopifyProductsJob;
use App\Models\Product;
use App\Models\SyncJob;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\Support\ShopifyProductFixtures;
use Tests\TestCase;

class ShopifyImportProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_successfully_with_mocked_shopify_responses(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->twice()
                ->andReturn(
                    ShopifyProductFixtures::productsResponse([
                        ShopifyProductFixtures::product(),
                    ]),
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(),
                    ]),
                );
        });

        $this->artisan('shopify:import-products')
            ->expectsOutputToContain('Shopify product import completed')
            ->expectsOutputToContain('Imported products: 1')
            ->expectsOutputToContain('Imported variants: 1')
            ->expectsOutputToContain('Failed items: 0')
            ->assertSuccessful();

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, SyncJob::query()->count());
    }

    public function test_command_can_dispatch_queue_job(): void
    {
        Bus::fake();

        $this->artisan('shopify:import-products --queue')
            ->expectsOutputToContain('dispatched to the queue')
            ->assertSuccessful();

        Bus::assertDispatched(ImportShopifyProductsJob::class);
    }
}
