<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectStuckSyncJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_stale_running_jobs_as_failed(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(20),
            'process_id' => 9_999_999,
        ]);

        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('Marked 1 stuck sync job(s) as failed.')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame('Marked stuck by sync:detect-stuck.', $job->error_message);
    }

    public function test_command_reports_no_stuck_jobs_when_none_found(): void
    {
        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('No stuck running sync jobs detected.')
            ->assertSuccessful();
    }
}
