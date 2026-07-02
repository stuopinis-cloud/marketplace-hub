<?php

namespace Tests\Feature\Console;

use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\Product;
use App\Models\Source;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VarleSkippedSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_summarizes_latest_varle_export_when_id_is_not_provided(): void
    {
        $olderJob = $this->createVarleExportJob();
        $latestJob = $this->createVarleExportJob(withItems: true);

        $this->artisan('varle:skipped-summary')
            ->expectsOutputToContain('Sync job ID: '.$latestJob->id)
            ->expectsOutputToContain('Total skipped/failed items: 2')
            ->expectsOutputToContain('Missing barcode')
            ->expectsOutputToContain('SKU-MISSING-1, SKU-MISSING-2')
            ->expectsOutputToContain('Total warnings: 1')
            ->expectsOutputToContain('Product old-handle: Category could not be resolved.')
            ->assertSuccessful();

        $this->assertNotSame($olderJob->id, $latestJob->id);
    }

    public function test_command_summarizes_specific_sync_job_by_id(): void
    {
        $job = $this->createVarleExportJob(withItems: true);

        $this->artisan('varle:skipped-summary', ['syncJobId' => (string) $job->id])
            ->expectsOutputToContain('Sync job ID: '.$job->id)
            ->expectsOutputToContain('Count: 2')
            ->assertSuccessful();
    }

    public function test_command_fails_when_no_varle_export_jobs_exist(): void
    {
        $this->artisan('varle:skipped-summary')
            ->expectsOutputToContain('No Varle export sync job found.')
            ->assertFailed();
    }

    private function createVarleExportJob(bool $withItems = false): SyncJob
    {
        $job = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
            'total_items' => 10,
            'success_items' => 8,
            'failed_items' => 2,
            'started_at' => now(),
            'finished_at' => now(),
            'context' => [
                'exported_variants' => 8,
                'skipped_variants' => 2,
                'warnings' => [
                    'Product old-handle: Category could not be resolved.',
                ],
            ],
        ]);

        if (! $withItems) {
            return $job;
        }

        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-1',
            'title' => 'Skipped Product',
            'handle' => 'skipped-product',
            'status' => 'active',
            'imported_at' => now(),
        ]);

        foreach (['SKU-MISSING-1', 'SKU-MISSING-2'] as $sku) {
            SyncJobItem::query()->create([
                'sync_job_id' => $job->id,
                'product_id' => $product->id,
                'sku' => $sku,
                'status' => SyncJobItemStatus::Failed,
                'message' => 'Missing barcode',
            ]);
        }

        return $job;
    }
}
