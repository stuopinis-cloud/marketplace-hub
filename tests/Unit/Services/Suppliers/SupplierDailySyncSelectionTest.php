<?php

namespace Tests\Unit\Services\Suppliers;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Marketplace\Varle\VarleExportResult;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleReadinessService;
use App\Services\Shopify\ShopifyImportResult;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SupplierDailySyncSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sync_runs_all_enabled_due_suppliers(): void
    {
        $this->createVariant('CSV-1', 'Vendor A');
        $this->createVariant('XML-1', 'Vendor B');
        $this->createVariant('PREZ-1', 'Prezioso');

        $csv = $this->createSupplier([
            'code' => 'due-csv',
            'name' => 'Due CSV',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/due.csv',
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        $xml = $this->createSupplier([
            'code' => 'due-xml',
            'name' => 'Due XML',
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://supplier.example/due.xml',
            'config' => [
                'xml_item_path' => '//item',
                'xml_sku_path' => 'sku',
                'xml_stock_path' => 'qty',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        $prezioso = $this->createSupplier([
            'code' => Supplier::CODE_PREZIOSO,
            'name' => 'Prezioso',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/prezioso.csv',
            'config' => [
                'csv_delimiter' => 'auto',
                'csv_sku_column' => 'CODICE',
                'csv_stock_column' => 'QTA',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        Http::fake([
            'https://supplier.example/due.csv' => Http::response("sku,qty\nCSV-1,3\n"),
            'https://supplier.example/due.xml' => Http::response(
                '<?xml version="1.0"?><items><item><sku>XML-1</sku><qty>4</qty></item></items>'
            ),
            'https://supplier.example/prezioso.csv' => Http::response("CODICE;QTA\nPREZ-1;5\n"),
        ]);

        $this->stubDailyNonSupplierStages();

        $result = app(DailyMarketplaceSync::class)->run();

        $this->assertTrue($result->successful);
        $codes = collect($result->summary['supplier_sync'])->pluck('code')->all();
        $this->assertEqualsCanonicalizing([$csv->code, $xml->code, $prezioso->code], $codes);
        $this->assertTrue(collect($result->summary['supplier_sync'])->every(
            fn (array $row): bool => isset($row['result'])
        ));
        $this->assertEqualsCanonicalizing(
            [
                Supplier::CONNECTOR_CSV_URL,
                Supplier::CONNECTOR_CSV_UPLOAD,
                Supplier::CONNECTOR_XML_URL,
                Supplier::CONNECTOR_JSON_API,
                Supplier::CONNECTOR_API,
                Supplier::CONNECTOR_MTAC,
                Supplier::CONNECTOR_HELIK_API,
            ],
            app(SupplierSyncManager::class)->supportedConnectorTypes(),
        );
    }

    public function test_inactive_suppliers_are_skipped(): void
    {
        $this->createSupplier([
            'code' => 'inactive-csv',
            'name' => 'Inactive',
            'enabled' => false,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/inactive.csv',
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        $manager = app(SupplierSyncManager::class);

        $this->assertCount(0, $manager->enabledSuppliers());
        $this->assertSame([], $manager->syncPublicationSuppliers());
    }

    public function test_disabled_supplier_sync_is_skipped(): void
    {
        $this->createSupplier([
            'code' => 'nosync-csv',
            'name' => 'No Sync',
            'sync_enabled' => false,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/nosync.csv',
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        $this->assertCount(0, app(SupplierSyncManager::class)->enabledSuppliers());
    }

    public function test_supplier_with_fresh_last_synced_at_is_skipped_unless_force(): void
    {
        $this->createVariant('SKU-1', 'Vendor');

        $supplier = $this->createSupplier([
            'code' => 'fresh-csv',
            'name' => 'Fresh',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/fresh.csv',
            'sync_interval_minutes' => 1440,
            'last_sync_at' => now()->subMinutes(10),
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        Http::fake([
            'https://supplier.example/fresh.csv' => Http::response("sku,qty\nSKU-1,1\n"),
        ]);

        $manager = app(SupplierSyncManager::class);

        $skipped = $manager->syncPublicationSuppliers();
        $this->assertSame('not_due', $skipped[0]['skipped'] ?? null);

        $forced = $manager->syncPublicationSuppliers(new SupplierSyncOptions(force: true));
        $this->assertArrayHasKey('result', $forced[0]);
        $this->assertSame($supplier->code, $forced[0]['code']);
    }

    public function test_previous_supplier_products_are_preserved_on_feed_failure(): void
    {
        $variant = $this->createVariant('KEEP-1', 'Vendor');
        $supplier = $this->createSupplier([
            'code' => 'keep-csv',
            'name' => 'Keep',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/keep.csv',
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'KEEP-1',
            'stock_quantity' => 11,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://supplier.example/keep.csv' => Http::response('', 500),
        ]);

        $results = app(SupplierSyncManager::class)->syncPublicationSuppliers(new SupplierSyncOptions(force: true));

        $this->assertArrayHasKey('error', $results[0]);
        $this->assertSame(11, SupplierProduct::query()->first()->stock_quantity);
        $supplier->refresh();
        $this->assertSame('failed', $supplier->last_sync_status);
        $this->assertNotNull($supplier->last_sync_message);
    }

    public function test_csv_url_prezioso_is_included_in_daily_supplier_sync(): void
    {
        $this->createVariant('PREZ-2', 'Prezioso');

        $this->createSupplier([
            'code' => Supplier::CODE_PREZIOSO,
            'name' => 'Prezioso',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/prezioso.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'config' => [
                'csv_delimiter' => ';',
                'csv_sku_column' => 'CODICE',
                'csv_stock_column' => 'QTA',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
            ],
        ]);

        Http::fake([
            'https://supplier.example/prezioso.csv' => Http::response("CODICE;QTA\nPREZ-2;8\n"),
        ]);

        $results = app(SupplierSyncManager::class)->syncPublicationSuppliers();

        $this->assertCount(1, $results);
        $this->assertSame('prezioso', $results[0]['code']);
        $this->assertArrayHasKey('result', $results[0]);
        $this->assertSame(1, $results[0]['result']->matched);
    }

    public function test_generic_xml_url_supplier_is_included_if_configured(): void
    {
        $this->createVariant('XML-1', 'Other Vendor');

        $this->createSupplier([
            'code' => 'acme-xml',
            'name' => 'Acme XML',
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://supplier.example/acme.xml',
            'config' => [
                'xml_item_path' => '//item',
                'xml_sku_path' => 'sku',
                'xml_stock_path' => 'qty',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        Http::fake([
            'https://supplier.example/acme.xml' => Http::response(
                '<?xml version="1.0"?><items><item><sku>XML-1</sku><qty>6</qty></item></items>'
            ),
        ]);

        $results = app(SupplierSyncManager::class)->syncPublicationSuppliers();

        $this->assertSame('acme-xml', $results[0]['code']);
        $this->assertArrayHasKey('result', $results[0]);
        $this->assertSame(1, $results[0]['result']->matched);
    }

    public function test_supplier_sync_all_respects_only_and_force(): void
    {
        $this->createVariant('A-1', 'Vendor');
        $this->createVariant('B-1', 'Vendor');

        $this->createSupplier([
            'code' => 'alpha',
            'name' => 'Alpha',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/alpha.csv',
            'sync_interval_minutes' => 1440,
            'last_sync_at' => now()->subMinutes(5),
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        $this->createSupplier([
            'code' => 'beta',
            'name' => 'Beta',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/beta.csv',
            'sync_interval_minutes' => 1440,
            'last_sync_at' => now()->subMinutes(5),
            'config' => ['csv_sku_column' => 'sku', 'csv_stock_column' => 'qty'],
        ]);

        Http::fake([
            'https://supplier.example/alpha.csv' => Http::response("sku,qty\nA-1,1\n"),
            'https://supplier.example/beta.csv' => Http::response("sku,qty\nB-1,2\n"),
        ]);

        $this->artisan('supplier:sync-all', ['--only' => 'alpha,beta'])
            ->expectsOutputToContain('Skipped: not due yet')
            ->assertSuccessful();

        $this->artisan('supplier:sync-all', ['--only' => 'alpha', '--force' => true])
            ->expectsOutputToContain('Alpha (alpha)')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSupplier(array $attributes): Supplier
    {
        return Supplier::query()->create(array_merge([
            'enabled' => true,
            'sync_enabled' => true,
            'auth_type' => Supplier::AUTH_NONE,
            'stock_priority' => 100,
            'in_stock_delivery_text' => '5-10 d.d.',
            'allow_backorder_export' => false,
            'availability_fallback_quantity' => 5,
            'force_daily_sync' => false,
            'config' => [],
        ], $attributes));
    }

    private function createVariant(string $sku, string $vendor): ProductVariant
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Product '.$sku,
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

    private function stubDailyNonSupplierStages(): void
    {
        $this->mock(ShopifyProductImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('import')->once()->andReturn(new ShopifyImportResult(1, 1, 1, 0));
        });

        $this->mock(VarleReadinessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshAll')->once()->andReturn(1);
        });

        $this->mock(VarleFeedPublisher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')->once()->andReturn(new VarleExportResult(
                syncJobId: 99,
                exportedVariants: 1,
                skippedVariants: 0,
                feedPath: '/tmp/feeds/varle.xml',
                publicUrl: 'https://example.test/feeds/varle.xml',
            ));
        });

        $this->mock(SyncJobFailedCsvExporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveSyncJob')->once()->andReturn(null);
        });
    }
}
