<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectStuckSyncJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.sync.stuck_after_minutes' => 10]);
    }

    public function test_command_marks_stale_running_jobs_as_failed(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(20),
            'process_id' => 9_999_999,
            'context' => [
                'stage' => 'importing_product',
                'current_product_handle' => 'stuck-product',
            ],
        ]);

        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('Marked 1 stuck sync job(s) as failed.')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame(
            'Marked failed automatically because heartbeat was stale for more than 10 minutes.',
            $job->error_message,
        );
        $this->assertSame('heartbeat_stale', data_get($job->context, 'stuck_reason'));
        $this->assertSame('importing_product', data_get($job->context, 'last_known_stage'));
        $this->assertSame('stuck-product', data_get($job->context, 'last_known_product_handle'));
    }

    public function test_command_leaves_recent_running_job_untouched(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subMinutes(2),
            'heartbeat_at' => now(),
        ]);

        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('No stuck running sync jobs detected.')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(SyncJobStatus::Running, $job->status);
        $this->assertNull($job->finished_at);
    }

    public function test_command_leaves_completed_job_untouched(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('sync:detect-stuck')->assertSuccessful();

        $job->refresh();
        $this->assertSame(SyncJobStatus::Completed, $job->status);
    }

    public function test_command_leaves_failed_job_untouched(): void
    {
        $job = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Failed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('sync:detect-stuck')->assertSuccessful();

        $job->refresh();
        $this->assertSame(SyncJobStatus::Failed, $job->status);
    }

    public function test_command_reports_no_stuck_jobs_when_none_found(): void
    {
        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('No stuck running sync jobs detected.')
            ->assertSuccessful();
    }
}
