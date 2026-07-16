<?php

namespace Tests\Unit\Services\Suppliers\Csv;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Csv\SupplierCsvFeedClient;
use App\Services\Suppliers\Csv\SupplierCsvParser;
use App\Services\Suppliers\Csv\SupplierCsvSupplierImporter;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierCsvSupplierImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_url_fetch_and_real_sync_upserts_supplier_products(): void
    {
        $variant = $this->createVariant('CSV-001', 'Vendor Name');
        $supplier = $this->createCsvUrlSupplier();

        Http::fake([
            'https://supplier.example/feed.csv' => Http::response("sku,qty\nCSV-001,7\n"),
        ]);

        $result = $this->makeImporter()->sync($supplier);

        $this->assertSame(1, $result->matched);
        $row = SupplierProduct::query()->where('supplier_sku', 'CSV-001')->first();
        $this->assertNotNull($row);
        $this->assertSame($variant->id, $row->product_variant_id);
        $this->assertSame(7, $row->stock_quantity);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->createVariant('CSV-DRY', 'Vendor Name');
        $supplier = $this->createCsvUrlSupplier();

        Http::fake([
            'https://supplier.example/feed.csv' => Http::response("sku,qty\nCSV-DRY,3\n"),
        ]);

        $this->makeImporter()->sync($supplier, new SupplierSyncOptions(dryRun: true));

        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_prezioso_style_semicolon_dry_run_matches_by_barcode_without_mutating(): void
    {
        $variant = $this->createVariant('LOCAL-SKU', 'Prezioso');
        $variant->update(['barcode' => '5901234123457']);

        $supplier = Supplier::query()->create([
            'name' => 'Prezioso',
            'code' => Supplier::CODE_PREZIOSO,
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/prezioso.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'sync_enabled' => true,
            'config' => [
                'csv_delimiter' => 'auto',
                'csv_encoding' => 'UTF-8',
                'csv_sku_column' => 'CODICE',
                'csv_barcode_column' => 'EAN',
                'csv_stock_column' => 'QTA',
                'vendor_scope' => ['Prezioso'],
            ],
        ]);

        Http::fake([
            'https://supplier.example/prezioso.csv' => Http::response("CODICE;EAN;QTA\nFEED-SKU;5901234123457;9\n"),
        ]);

        $result = $this->makeImporter()->sync($supplier, new SupplierSyncOptions(dryRun: true));

        $this->assertSame(1, $result->matched);
        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_failed_fetch_preserves_previous_stock(): void
    {
        $variant = $this->createVariant('CSV-KEEP', 'Vendor Name');
        $supplier = $this->createCsvUrlSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'CSV-KEEP',
            'stock_quantity' => 11,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://supplier.example/feed.csv' => Http::response('', 500),
        ]);

        try {
            $this->makeImporter()->sync($supplier);
        } catch (\RuntimeException) {
        }

        $this->assertSame(11, SupplierProduct::query()->first()->stock_quantity);
    }

    public function test_csv_upload_parses_from_private_storage(): void
    {
        Storage::fake('local');
        $variant = $this->createVariant('UP-001', 'Vendor Name');
        $supplier = $this->createCsvUploadSupplier('suppliers/csv/1/feed.csv');
        Storage::disk('local')->put('suppliers/csv/1/feed.csv', "sku,qty\nUP-001,9\n");

        $result = $this->makeImporter()->sync($supplier);

        $this->assertSame(1, $result->matched);
        $this->assertSame($variant->id, SupplierProduct::query()->first()->product_variant_id);
        $this->assertSame(9, SupplierProduct::query()->first()->stock_quantity);
    }

    public function test_duplicate_supplier_sku_is_detected(): void
    {
        $this->createVariant('DUP-1', 'Vendor Name');
        $supplier = $this->createCsvUrlSupplier();

        Http::fake([
            'https://supplier.example/feed.csv' => Http::response("sku,qty\nDUP-1,1\nDUP-1,2\n"),
        ]);

        $result = $this->makeImporter()->sync($supplier);

        $this->assertSame(2, $result->duplicateSupplierSku);
        $this->assertSame(2, $result->failedRows);
        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_unmatched_rows_are_stored(): void
    {
        $supplier = $this->createCsvUrlSupplier();

        Http::fake([
            'https://supplier.example/feed.csv' => Http::response("sku,qty\nUNKNOWN-1,4\n"),
        ]);

        $result = $this->makeImporter()->sync($supplier);

        $this->assertSame(1, $result->unmatched);
        $row = SupplierProduct::query()->where('supplier_sku', 'UNKNOWN-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(SupplierProduct::MATCH_STATUS_UNMATCHED, $row->match_status);
    }

    private function makeImporter(): SupplierCsvSupplierImporter
    {
        return new SupplierCsvSupplierImporter(
            app(SupplierCsvFeedClient::class),
            new SupplierCsvParser,
            app(\App\Services\Suppliers\SupplierStockSyncOrchestrator::class),
        );
    }

    private function createCsvUrlSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'CSV URL Supplier',
            'code' => 'csv-url',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/feed.csv',
            'sync_enabled' => true,
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
                'vendor_scope' => ['Vendor Name'],
            ],
        ]);
    }

    private function createCsvUploadSupplier(string $path): Supplier
    {
        return Supplier::query()->create([
            'name' => 'CSV Upload Supplier',
            'code' => 'csv-upload',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_UPLOAD,
            'sync_enabled' => true,
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
                'uploaded_file_path' => $path,
                'vendor_scope' => ['Vendor Name'],
            ],
        ]);
    }

    private function createVariant(string $sku, string $vendor): ProductVariant
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'CSV Product',
            'vendor' => $vendor,
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => $sku,
            'barcode' => '4770000000'.random_int(100, 999),
            'price' => 20,
        ]);
    }
}
