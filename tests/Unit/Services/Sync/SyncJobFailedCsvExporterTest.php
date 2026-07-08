<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\ProductStatus;
use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncJobFailedCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_export_writes_csv_with_header_product_and_variant_details(): void
    {
        [$syncJob, $item] = $this->createFailedExportJob();

        $exporter = $this->app->make(SyncJobFailedCsvExporter::class);
        $relativePath = $exporter->export($syncJob);

        $contents = Storage::disk('public')->get($relativePath);
        $lines = preg_split('/\R/', trim($contents));
        $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));
        $row = str_getcsv($lines[1]);

        $this->assertSame($exporter->headers(), $header);
        $this->assertSame((string) $syncJob->id, $row[0]);
        $this->assertSame('failed', $row[2]);
        $this->assertSame('Missing barcode', $row[3]);
        $this->assertSame('skipped-product', $row[5]);
        $this->assertSame('missing_barcode', $row[array_search('issue_code', $header, true)]);
    }

    /**
     * @return array{0: SyncJob, 1: SyncJobItem}
     */
    private function createFailedExportJob(): array
    {
        $syncJob = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
            'failed_items' => 1,
        ]);

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
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-1',
            'sku' => 'SKU-MISSING',
            'title' => 'Variant title',
            'price' => 10,
        ]);

        $item = SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'sku' => 'SKU-MISSING',
            'status' => SyncJobItemStatus::Failed,
            'message' => 'Missing barcode',
            'payload' => [
                'issue_code' => 'missing_barcode',
                'varle_export_status' => 'auto',
            ],
        ]);

        return [$syncJob, $item];
    }
}
