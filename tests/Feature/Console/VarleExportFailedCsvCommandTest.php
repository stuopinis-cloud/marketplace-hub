<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VarleExportFailedCsvCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_command_exports_csv_for_provided_sync_job(): void
    {
        $job = $this->createExportJobWithFailedItem();

        $this->artisan('varle:export-failed-csv', ['syncJobId' => (string) $job->id])
            ->expectsOutputToContain('Failed/skipped items exported to CSV.')
            ->expectsOutputToContain('Sync job ID: '.$job->id)
            ->expectsOutputToContain('exports/varle_failed_'.$job->id.'.csv')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('exports/varle_failed_'.$job->id.'.csv');
    }

    public function test_command_uses_latest_varle_export_job_when_id_is_not_provided(): void
    {
        $this->createExportJobWithFailedItem();
        $latestJob = $this->createExportJobWithFailedItem();

        $this->artisan('varle:export-failed-csv')
            ->expectsOutputToContain('Sync job ID: '.$latestJob->id)
            ->assertSuccessful();

        Storage::disk('public')->assertExists('exports/varle_failed_'.$latestJob->id.'.csv');
    }

    public function test_command_fails_when_no_varle_export_job_exists(): void
    {
        $this->artisan('varle:export-failed-csv')
            ->expectsOutputToContain('No Varle export sync job found.')
            ->assertFailed();
    }

    private function createExportJobWithFailedItem(): SyncJob
    {
        $job = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
            'failed_items' => 1,
        ]);

        SyncJobItem::query()->create([
            'sync_job_id' => $job->id,
            'sku' => 'FAILED-SKU',
            'status' => SyncJobItemStatus::Failed,
            'message' => 'Missing barcode',
        ]);

        return $job;
    }
}
