<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleXmlFeedValidationResult;
use App\Services\Marketplace\Varle\VarleXmlFeedValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleExportSyncJobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_successful_export_does_not_leave_running_job(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $this->artisan('varle:export-xml')->assertSuccessful();

        $job = SyncJob::query()->sole();

        $this->assertNotSame(SyncJobStatus::Running, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->heartbeat_at);
        $this->assertNotNull($job->process_id);
        $this->assertNull($job->error_message);
        $this->assertSame('finished', data_get($job->context, 'stage'));
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
    }

    public function test_partial_export_does_not_leave_running_job(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'exportable-one'],
        );
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'exportable-two'],
            variantOverrides: ['barcode' => null],
        );

        $this->artisan('varle:export-xml');

        $job = SyncJob::query()->sole();

        $this->assertSame(SyncJobStatus::Partial, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->heartbeat_at);
        $this->assertGreaterThan(0, $job->success_items);
        $this->assertGreaterThan(0, $job->failed_items);
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
    }

    public function test_export_sets_heartbeat_and_process_id_during_run(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $this->artisan('varle:export-xml')->assertSuccessful();

        $job = SyncJob::query()->sole();

        $this->assertNotNull($job->heartbeat_at);
        $this->assertNotNull($job->process_id);
        $this->assertNotNull(data_get($job->context, 'last_progress_at'));
    }

    public function test_validation_failure_marks_sync_job_failed_and_preserves_live_feed(): void
    {
        VarleCatalogFixtures::createExportableVariant();
        Storage::disk('public')->put('feeds/varle.xml', '<?xml version="1.0"?><products><product><quantity>2</quantity></product></products>');

        $this->mock(VarleXmlFeedValidator::class, function ($mock): void {
            $mock->shouldReceive('validate')
                ->once()
                ->andReturn(VarleXmlFeedValidationResult::invalid(['Feed contains zero-quantity products.']));
        });

        try {
            app(VarleFeedPublisher::class)->publish();
            $this->fail('Expected publication to fail validation.');
        } catch (\RuntimeException) {
        }

        $job = SyncJob::query()->sole();

        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->heartbeat_at);
        $this->assertSame('Feed contains zero-quantity products.', $job->error_message);
        $this->assertSame('failed', data_get($job->context, 'stage'));
        $this->assertTrue(Storage::disk('public')->exists('feeds/varle.xml'));
        $this->assertFalse(Storage::disk('public')->exists('feeds/varle.xml.tmp'));
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
    }

    public function test_atomic_replace_failure_marks_sync_job_failed(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        File::partialMock()->shouldReceive('replace')->andThrow(new \RuntimeException('Atomic replace failed'));

        try {
            app(VarleFeedPublisher::class)->publish();
            $this->fail('Expected atomic replace to fail.');
        } catch (\RuntimeException) {
        }

        $job = SyncJob::query()->sole();

        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->heartbeat_at);
        $this->assertSame('Atomic replace failed', $job->error_message);
        $this->assertSame('failed', data_get($job->context, 'stage'));
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
    }

    public function test_detect_stuck_marks_varle_export_with_null_heartbeat_using_updated_at(): void
    {
        config(['marketplace.sync.stuck_after_minutes' => 10]);

        $job = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => null,
            'success_items' => 100,
            'failed_items' => 20,
            'context' => ['stage' => 'exported_draft'],
        ]);

        $job->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        $this->artisan('sync:detect-stuck')
            ->expectsOutputToContain('Marked 1 stuck sync job(s) as failed.')
            ->assertSuccessful();

        $job->refresh();

        $this->assertSame(SyncJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->heartbeat_at);
        $this->assertNotNull($job->error_message);
    }
}
