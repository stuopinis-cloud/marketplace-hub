<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SyncStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_shopify_job_is_rendered(): void
    {
        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Failed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(15),
            'total_items' => 743,
            'success_items' => 741,
            'failed_items' => 1,
            'error_message' => 'API timeout',
            'context' => [
                'current_product_handle' => 'sample-handle',
                'stage' => 'failed',
            ],
        ]);

        $this->artisan('sync:status', [
            '--source' => 'shopify',
            '--latest' => true,
        ])
            ->expectsOutputToContain('Source: shopify')
            ->expectsOutputToContain('Status: failed')
            ->expectsOutputToContain('Health: failed')
            ->expectsOutputToContain('Error: API timeout')
            ->assertSuccessful();
    }

    public function test_running_filter_only_shows_running_jobs(): void
    {
        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $this->artisan('sync:status', [
            '--source' => 'shopify',
            '--running' => true,
        ])
            ->expectsOutputToContain('Status: running')
            ->doesntExpectOutputToContain('Status: completed')
            ->assertSuccessful();
    }

    public function test_json_output_contains_health_payload(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(5),
            'total_items' => 10,
            'success_items' => 10,
        ]);

        Artisan::call('sync:status', [
            '--source' => 'shopify',
            '--latest' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($payload);
        $this->assertSame($job->id, $payload[0]['id']);
        $this->assertSame('completed', $payload[0]['health']['health_status']);
    }
}
