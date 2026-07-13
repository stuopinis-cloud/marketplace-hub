<?php

namespace Tests\Unit\Services\Suppliers\Mtac;

use App\Enums\ProductStatus;
use App\Enums\SyncJobStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use App\Services\Suppliers\Mtac\MtacFeedClient;
use App\Services\Suppliers\Mtac\MtacSkuMatcher;
use App\Services\Suppliers\Mtac\MtacSupplierImporter;
use App\Services\Suppliers\Mtac\MtacSupplierSyncOptions;
use App\Services\Suppliers\Mtac\MtacXmlParser;
use App\Services\Suppliers\SupplierProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MtacSupplierImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_sync_writes_supplier_products(): void
    {
        $variant = $this->createMtacVariant('MTAC-001');
        $supplier = $this->createMtacSupplier();

        $this->mock(MtacFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn($this->feedXml([
                ['sku' => 'MTAC-001', 'stock' => '15'],
            ]));
        });

        $result = $this->makeImporter()->sync(new MtacSupplierSyncOptions);

        $this->assertSame(1, $result->matched);
        $row = SupplierProduct::query()->where('supplier_sku', 'MTAC-001')->first();
        $this->assertNotNull($row);
        $this->assertSame($variant->id, $row->product_variant_id);
        $this->assertSame(15, $row->stock_quantity);
        $this->assertSame(SupplierProduct::MATCH_STATUS_MATCHED, $row->match_status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->createMtacVariant('MTAC-DRY');
        $this->createMtacSupplier();

        $this->mock(MtacFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn($this->feedXml([
                ['sku' => 'MTAC-DRY', 'stock' => '3'],
            ]));
        });

        $this->makeImporter()->sync(new MtacSupplierSyncOptions(dryRun: true));

        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_failed_fetch_preserves_previous_stock(): void
    {
        $variant = $this->createMtacVariant('MTAC-KEEP');
        $supplier = $this->createMtacSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'MTAC-KEEP',
            'stock_quantity' => 9,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(MtacFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('timeout'));
        });

        try {
            $this->makeImporter()->sync(new MtacSupplierSyncOptions);
        } catch (\RuntimeException) {
        }

        $this->assertSame(9, SupplierProduct::query()->first()->stock_quantity);
    }

    public function test_full_success_marks_missing_rows_unavailable(): void
    {
        $supplier = $this->createMtacSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'OLD-SKU',
            'stock_quantity' => 4,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_seen_at' => now()->subDay(),
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(MtacFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn($this->feedXml([
                ['sku' => 'NEW-SKU', 'stock' => '1'],
            ]));
        });

        $this->makeImporter()->sync(new MtacSupplierSyncOptions);

        $old = SupplierProduct::query()->where('supplier_sku', 'OLD-SKU')->first();
        $this->assertSame(0, $old->stock_quantity);
        $this->assertSame(SupplierProduct::AVAILABILITY_MISSING_FROM_FEED, $old->availability_status);
    }

    private function makeImporter(): MtacSupplierImporter
    {
        return new MtacSupplierImporter(
            new SupplierProvisioner,
            app(MtacFeedClient::class),
            new MtacXmlParser,
            app(\App\Services\Suppliers\SupplierStockSyncOrchestrator::class),
        );
    }

    private function createMtacSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'endpoint_url' => 'https://m-tac.pl/xml?id=42',
            'sync_enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);
    }

    private function createMtacVariant(string $sku): ProductVariant
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
            'title' => 'M-Tac Product',
            'vendor' => 'M-Tac',
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => $sku,
            'barcode' => '4770000000999',
            'price' => 20,
        ]);
    }

    /**
     * @param  array<int, array{sku: string, stock: string}>  $rows
     */
    private function feedXml(array $rows): string
    {
        $entries = '';

        foreach ($rows as $row) {
            $entries .= '<entry><g:SKU>'.$row['sku'].'</g:SKU><g:stock>'.$row['stock'].'</g:stock></entry>';
        }

        return '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">'.$entries.'</feed>';
    }
}
