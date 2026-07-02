<?php

namespace Tests\Feature;

use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VarleFailedExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_download_route_requires_authentication(): void
    {
        $job = $this->createExportJobWithFailedItem();

        $this->get('/exports/varle-failed/'.$job->id.'.csv')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_download_failed_csv(): void
    {
        $user = User::factory()->create();
        $job = $this->createExportJobWithFailedItem();

        $response = $this->actingAs($user)->get('/exports/varle-failed/'.$job->id.'.csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertDownload('varle_failed_'.$job->id.'.csv');

        Storage::disk('public')->assertExists('exports/varle_failed_'.$job->id.'.csv');
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
