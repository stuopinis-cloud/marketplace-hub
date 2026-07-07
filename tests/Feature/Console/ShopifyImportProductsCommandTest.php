<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
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

        $syncJob = SyncJob::query()->firstOrFail();
        $this->assertSame(SyncJobStatus::Completed, $syncJob->status);
        $this->assertNotNull($syncJob->started_at);
        $this->assertNotNull($syncJob->finished_at);
    }

    public function test_command_can_dispatch_queue_job(): void
    {
        Bus::fake();

        $this->artisan('shopify:import-products --queue')
            ->expectsOutputToContain('dispatched to the queue')
            ->assertSuccessful();

        Bus::assertDispatched(ImportShopifyProductsJob::class);
    }

    public function test_limit_option_exists_and_limits_import(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->times(3)
                ->andReturn(
                    ShopifyProductFixtures::productsResponse([
                        ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1001', 'handle' => 'product-one']),
                        ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1002', 'handle' => 'product-two']),
                        ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1003', 'handle' => 'product-three']),
                    ]),
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(['id' => 'gid://shopify/ProductVariant/2001']),
                    ]),
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(['id' => 'gid://shopify/ProductVariant/2002']),
                    ]),
                );
        });

        $this->artisan('shopify:import-products --limit=2')
            ->expectsOutputToContain('Imported products: 2')
            ->assertSuccessful();

        $this->assertSame(2, Product::query()->count());
    }

    public function test_handle_option_imports_single_product(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->once()
                ->withArgs(function (string $query, array $variables): bool {
                    return str_contains($query, 'ImportActiveProducts')
                        && ($variables['query'] ?? null) === 'handle:target-handle';
                })
                ->andReturn(
                    ShopifyProductFixtures::productsResponse([
                        ShopifyProductFixtures::product(['handle' => 'target-handle']),
                    ]),
                );

            $mock->shouldReceive('query')
                ->once()
                ->andReturn(
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(),
                    ]),
                );
        });

        $this->artisan('shopify:import-products --handle=target-handle')
            ->expectsOutputToContain('Imported products: 1')
            ->assertSuccessful();

        $this->assertSame(1, Product::query()->count());
    }

    public function test_verbose_option_prints_progress(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->twice()
                ->andReturn(
                    ShopifyProductFixtures::productsResponse([
                        ShopifyProductFixtures::product(['handle' => 'verbose-product']),
                    ]),
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(),
                    ]),
                );
        });

        $this->artisan('shopify:import-products --limit=1 -v')
            ->expectsOutputToContain('[1/1] verbose-product | variants:')
            ->assertSuccessful();
    }

    public function test_command_blocks_when_recent_running_import_exists(): void
    {
        $running = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
        ]);

        $this->artisan('shopify:import-products')
            ->expectsOutputToContain("Shopify import #{$running->id} is already running")
            ->assertFailed();
    }

    public function test_force_option_bypasses_running_import_protection(): void
    {
        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
        ]);

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

        $this->artisan('shopify:import-products --force')
            ->expectsOutputToContain('Shopify product import completed')
            ->assertSuccessful();
    }

    public function test_cancel_running_option_requests_cancellation(): void
    {
        $running = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
        ]);

        $this->artisan('shopify:import-products --cancel-running')
            ->expectsOutputToContain('Cancellation requested for 1 running Shopify import(s).')
            ->assertSuccessful();

        $running->refresh();
        $this->assertNotNull($running->cancel_requested_at);
    }
}
