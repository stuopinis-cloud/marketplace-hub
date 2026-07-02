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

        $this->assertSame('exports/varle_failed_'.$syncJob->id.'.csv', $relativePath);
        Storage::disk('public')->assertExists($relativePath);

        $contents = Storage::disk('public')->get($relativePath);
        $lines = preg_split('/\R/', trim($contents));
        $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));
        $row = str_getcsv($lines[1]);

        $this->assertSame($exporter->headers(), $header);
        $this->assertSame((string) $syncJob->id, $row[0]);
        $this->assertSame((string) $item->id, $row[1]);
        $this->assertSame('failed', $row[2]);
        $this->assertSame('Missing barcode', $row[3]);
        $this->assertSame('SKU-MISSING', $row[4]);
        $this->assertSame('Skipped Product', $row[6]);
        $this->assertSame('skipped-product', $row[7]);
        $this->assertSame('Variant title', $row[9]);
        $this->assertSame('SKU-MISSING', $row[10]);
        $this->assertSame('', $row[11]);
        $this->assertSame('auto', $row[12]);
        $this->assertSame('1', $row[13]);
        $this->assertSame('1', $row[14]);
    }

    public function test_export_includes_varle_export_context_columns(): void
    {
        [$syncJob, $item] = $this->createFailedExportJob();

        $relativePath = $this->app->make(SyncJobFailedCsvExporter::class)->export($syncJob);
        $lines = preg_split('/\R/', trim(Storage::disk('public')->get($relativePath)));
        $row = str_getcsv($lines[1]);

        $this->assertSame('auto', $row[12]);
        $this->assertSame('1', $row[13]);
        $this->assertSame('1', $row[14]);
        $this->assertNotSame('', $row[15]);
    }

    public function test_export_escapes_commas_and_quotes_in_csv_values(): void
    {
        [$syncJob] = $this->createFailedExportJob(message: 'Missing "barcode", urgent');

        $relativePath = $this->app->make(SyncJobFailedCsvExporter::class)->export($syncJob);
        $contents = Storage::disk('public')->get($relativePath);

        $this->assertStringContainsString('"Missing ""barcode"", urgent"', $contents);
    }

    public function test_export_includes_warning_rows_from_sync_job_context(): void
    {
        [$syncJob] = $this->createFailedExportJob(
            contextWarnings: ['Product old-handle: Category could not be resolved.'],
        );

        $relativePath = $this->app->make(SyncJobFailedCsvExporter::class)->export($syncJob);
        $lines = preg_split('/\R/', trim(Storage::disk('public')->get($relativePath)));

        $this->assertCount(3, $lines);
        $warningRow = str_getcsv($lines[2]);

        $this->assertSame('warning', $warningRow[2]);
        $this->assertSame('Product old-handle: Category could not be resolved.', $warningRow[3]);
        $this->assertSame('old-handle', $warningRow[7]);
    }

    public function test_resolve_sync_job_returns_latest_varle_export_when_id_is_null(): void
    {
        SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Completed,
        ]);

        $latest = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
        ]);

        $resolved = $this->app->make(SyncJobFailedCsvExporter::class)->resolveSyncJob(null);

        $this->assertNotNull($resolved);
        $this->assertSame($latest->id, $resolved->id);
    }

    /**
     * @param  array<int, string>  $contextWarnings
     * @return array{0: SyncJob, 1: SyncJobItem}
     */
    private function createFailedExportJob(
        string $message = 'Missing barcode',
        array $contextWarnings = [],
    ): array {
        $syncJob = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
            'failed_items' => 1,
            'context' => [
                'warnings' => $contextWarnings,
            ],
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
            'message' => $message,
            'payload' => [
                'varle_export_status' => 'auto',
                'category_mapping_export_enabled' => true,
                'product_is_published' => true,
                'product_published_at' => now()->toDateTimeString(),
            ],
        ]);

        return [$syncJob, $item];
    }
}
