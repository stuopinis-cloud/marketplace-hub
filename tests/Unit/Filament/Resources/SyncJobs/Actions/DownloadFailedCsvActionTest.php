<?php

namespace Tests\Unit\Filament\Resources\SyncJobs\Actions;

use App\Enums\SyncJobStatus;
use App\Filament\Resources\SyncJobs\Actions\DownloadFailedCsvAction;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadFailedCsvActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_exporter_used_by_filament_action_downloads_csv_for_export_job(): void
    {
        $syncJob = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
        ]);

        $exporter = $this->app->make(SyncJobFailedCsvExporter::class);
        $response = $exporter->downloadResponse($syncJob);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('varle_failed_'.$syncJob->id.'.csv', (string) $response->headers->get('content-disposition'));
        Storage::disk('public')->assertExists('exports/varle_failed_'.$syncJob->id.'.csv');
    }

    public function test_download_failed_csv_action_can_be_created(): void
    {
        $action = DownloadFailedCsvAction::make();

        $this->assertSame('downloadFailedCsv', $action->getName());
    }
}
