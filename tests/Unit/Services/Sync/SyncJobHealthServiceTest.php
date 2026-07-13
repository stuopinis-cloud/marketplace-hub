<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncJobHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncJobHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.sync.stuck_after_minutes' => 10]);
        $this->service = app(SyncJobHealthService::class);
    }

    public function test_recent_heartbeat_running_job_is_healthy_running(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subMinutes(5),
            'heartbeat_at' => now()->subMinute(),
        ]);

        $health = $this->service->assess($job);

        $this->assertSame(SyncJobHealthService::HEALTH_HEALTHY_RUNNING, $health['health_status']);
        $this->assertTrue($health['is_running']);
        $this->assertFalse($health['is_stuck']);
        $this->assertSame('Running', $health['label']);
        $this->assertSame('Import is running normally.', $health['human_message']);
    }

    public function test_old_heartbeat_running_job_is_stuck(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(18),
        ]);

        $health = $this->service->assess($job);

        $this->assertSame(SyncJobHealthService::HEALTH_STUCK, $health['health_status']);
        $this->assertTrue($health['is_stuck']);
        $this->assertStringContainsString('Import appears stuck', $health['human_message']);
    }

    public function test_null_heartbeat_and_old_started_at_is_stuck(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subMinutes(20),
            'heartbeat_at' => null,
        ]);

        $this->assertTrue($this->service->isStuck($job));
        $this->assertSame(SyncJobHealthService::HEALTH_STUCK, $this->service->assess($job)['health_status']);
    }

    public function test_completed_job_is_completed_health(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'heartbeat_at' => now()->subMinutes(30),
        ]);

        $health = $this->service->assess($job);

        $this->assertSame(SyncJobHealthService::HEALTH_COMPLETED, $health['health_status']);
        $this->assertTrue($health['is_completed']);
        $this->assertStringContainsString('completed successfully', $health['human_message']);
    }

    public function test_failed_job_is_failed_health(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Failed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(10),
            'error_message' => 'API timeout',
        ]);

        $health = $this->service->assess($job);

        $this->assertSame(SyncJobHealthService::HEALTH_FAILED, $health['health_status']);
        $this->assertTrue($health['is_failed']);
        $this->assertSame('Import failed: API timeout', $health['human_message']);
    }

    public function test_cancelled_job_is_cancelled_health(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Cancelled,
            'started_at' => now()->subHour(),
            'cancelled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);

        $health = $this->service->assess($job);

        $this->assertSame(SyncJobHealthService::HEALTH_CANCELLED, $health['health_status']);
        $this->assertTrue($health['is_cancelled']);
    }

    public function test_progress_percentage_uses_processed_items_over_total(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'total_items' => 100,
            'success_items' => 40,
            'failed_items' => 10,
            'context' => [
                'current_product_index' => 55,
            ],
        ]);

        $metrics = $this->service->progressMetrics($job);
        $health = $this->service->assess($job);

        $this->assertSame(50.0, $metrics['percent']);
        $this->assertSame('55 / 100', $metrics['label']);
        $this->assertSame(50.0, $health['progress_percent']);
    }
}
