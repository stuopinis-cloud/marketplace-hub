<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.shop' => 'c88j71-wn.myshopify.com',
            'shopify.client_id' => 'test-client-id',
            'shopify.client_secret' => 'test-client-secret',
        ]);
    }

    public function test_health_check_command_passes_when_environment_is_ready(): void
    {
        Storage::fake('public');

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'success_items' => 10,
            'failed_items' => 0,
        ]);

        SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Completed,
            'success_items' => 8,
            'failed_items' => 0,
        ]);

        $this->artisan('marketplace:health-check')
            ->expectsOutputToContain('Marketplace Hub health check')
            ->expectsOutputToContain('Shopify import #')
            ->expectsOutputToContain('Varle export #')
            ->assertSuccessful();
    }

    public function test_health_check_command_fails_when_shopify_credentials_are_missing(): void
    {
        config([
            'shopify.shop' => null,
            'shopify.client_id' => null,
            'shopify.client_secret' => null,
        ]);

        $this->artisan('marketplace:health-check')
            ->expectsOutputToContain('Shopify credentials are incomplete')
            ->assertFailed();
    }
}
