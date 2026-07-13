<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobProcessInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncJobProcessInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_stale_running_job_without_live_process_as_stuck(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(20),
            'process_id' => 9_999_999,
        ]);

        $inspector = app(SyncJobProcessInspector::class);

        $this->assertTrue($inspector->shouldMarkStuck($job));
    }

    public function test_does_not_mark_recent_running_job_as_stuck(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => 9_999_999,
        ]);

        $inspector = app(SyncJobProcessInspector::class);

        $this->assertFalse($inspector->shouldMarkStuck($job));
    }
}
