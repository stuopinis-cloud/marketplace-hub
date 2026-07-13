<?php

namespace Tests\Unit\Services\Suppliers\Helik;

use App\Enums\ProductStatus;
use App\Enums\SyncJobStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use App\Services\Suppliers\Helik\HelikFeedClient;
use App\Services\Suppliers\Helik\HelikResponseParser;
use App\Services\Suppliers\Helik\HelikSupplierImporter;
use App\Services\Suppliers\SupplierProvisioner;
use App\Services\Suppliers\SupplierSkuMatcher;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class HelikSupplierImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_sync_upserts_supplier_products_for_helikon_vendor(): void
    {
        $variant = $this->createVariant('Helikon-Tex', 'HEL-001');
        $this->createHelikSupplier();

        $this->mock(HelikFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn(json_encode([
                'Value' => [['SKU' => 'HEL-001', 'Quantity' => 8]],
            ]));
        });

        $result = $this->makeImporter()->sync(new SupplierSyncOptions);

        $this->assertSame(1, $result->matched);
        $row = SupplierProduct::query()->where('supplier_sku', 'HEL-001')->first();
        $this->assertNotNull($row);
        $this->assertSame($variant->id, $row->product_variant_id);
        $this->assertSame(8, $row->stock_quantity);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->createVariant('Direct-Action', 'DA-001');
        $this->createHelikSupplier();

        $this->mock(HelikFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn(json_encode([
                'Value' => [['SKU' => 'DA-001', 'Quantity' => 2]],
            ]));
        });

        $this->makeImporter()->sync(new SupplierSyncOptions(dryRun: true));

        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_failed_fetch_preserves_previous_stock(): void
    {
        $variant = $this->createVariant('Helikon-Tex', 'HEL-KEEP');
        $supplier = $this->createHelikSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'HEL-KEEP',
            'stock_quantity' => 6,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(HelikFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('timeout'));
        });

        try {
            $this->makeImporter()->sync(new SupplierSyncOptions);
        } catch (\RuntimeException) {
        }

        $this->assertSame(6, SupplierProduct::query()->first()->stock_quantity);
    }

    public function test_wrong_vendor_is_not_matched(): void
    {
        $this->createVariant('Other Vendor', 'HEL-001');
        $this->createHelikSupplier();

        $this->mock(HelikFeedClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andReturn(json_encode([
                'Value' => [['SKU' => 'HEL-001', 'Quantity' => 4]],
            ]));
        });

        $result = $this->makeImporter()->sync(new SupplierSyncOptions);

        $this->assertSame(1, $result->unmatched);
        $this->assertNull(SupplierProduct::query()->first()->product_variant_id);
    }

    private function makeImporter(): HelikSupplierImporter
    {
        return new HelikSupplierImporter(
            new SupplierProvisioner,
            app(HelikFeedClient::class),
            new HelikResponseParser,
            app(SupplierStockSyncOrchestrator::class),
        );
    }

    private function createHelikSupplier(): Supplier
    {
        config(['services.entirem.token' => 'test-token']);

        return (new SupplierProvisioner)->ensureHelikSupplier();
    }

    private function createVariant(string $vendor, string $sku): ProductVariant
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
            'title' => 'Product',
            'vendor' => $vendor,
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
}
