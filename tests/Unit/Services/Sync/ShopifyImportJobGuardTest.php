<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Sync\ShopifyImportJobGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyImportJobGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_stale_running_shopify_imports_as_failed(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(20),
            'process_id' => 9_999_999,
        ]);

        $marked = $this->app->make(ShopifyImportJobGuard::class)->markStaleRunningImportsAsFailed();

        $this->assertSame(1, $marked);
        $job->refresh();
        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
    }

    public function test_find_blocking_running_import_returns_active_recent_job(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
        ]);

        $blocking = $this->app->make(ShopifyImportJobGuard::class)->findBlockingRunningImport();

        $this->assertNotNull($blocking);
        $this->assertSame($job->id, $blocking->id);
    }

    public function test_request_cancel_running_imports_sets_cancel_requested_at(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
        ]);

        $count = $this->app->make(ShopifyImportJobGuard::class)->requestCancelRunningImports();

        $this->assertSame(1, $count);
        $job->refresh();
        $this->assertNotNull($job->cancel_requested_at);
    }
}
