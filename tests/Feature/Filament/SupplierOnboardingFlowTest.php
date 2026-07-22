<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Jobs\SyncSupplierStockJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Enums\ProductStatus;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use App\Services\Suppliers\SupplierDryRunService;
use App\Services\Suppliers\SupplierFeedPreviewService;
use App\Services\Suppliers\SupplierFormDataFactory;
use App\Services\Suppliers\SupplierOnboardingValidator;
use App\Services\Suppliers\SupplierSyncManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierOnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_csv_url_supplier_entirely_from_filament(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Acme CSV',
                'code' => 'acme-csv',
                'enabled' => true,
                'sync_enabled' => true,
                'connector_type' => Supplier::CONNECTOR_CSV_URL,
                'endpoint_url' => 'https://supplier.example/acme.csv',
                'auth_type' => Supplier::AUTH_NONE,
                'config' => [
                    'response_type' => 'csv',
                    'csv_delimiter' => 'auto',
                    'csv_encoding' => 'UTF-8',
                    'csv_has_header' => true,
                    'csv_sku_column' => 'sku',
                    'csv_stock_column' => 'qty',
                    'matching_strategy' => 'sku_global',
                    'match_by_barcode' => false,
                    'require_vendor_scope' => false,
                    'vendor_scope' => [],
                    'missing_from_feed_policy' => 'keep_previous',
                ],
                'sync_interval_minutes' => 1440,
                'force_daily_sync' => true,
                'availability_fallback_quantity' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', 'acme-csv')->sole();
        $this->assertSame(Supplier::CONNECTOR_CSV_URL, $supplier->connector_type);
        $this->assertSame('sku', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('qty', data_get($supplier->config, 'csv_stock_column'));
        $this->assertSame('sku_global', data_get($supplier->config, 'matching_strategy'));
        $this->assertTrue($supplier->sync_enabled);
        $this->assertNull(data_get($supplier->config, 'password'));
        $this->assertNull(data_get($supplier->config, 'token'));
    }

    public function test_csv_preview_detects_headers_and_mapping_options(): void
    {
        Http::fake([
            'https://supplier.example/preview.csv' => Http::response("sku,qty,ean\nA-1,2,123\n"),
        ]);

        $supplier = new Supplier([
            'code' => 'preview-csv',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/preview.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'config' => [
                'csv_delimiter' => 'auto',
                'csv_encoding' => 'UTF-8',
                'csv_has_header' => true,
            ],
        ]);

        $preview = app(SupplierFeedPreviewService::class)->preview($supplier);

        $this->assertNull($preview['error'] ?? null);
        $this->assertSame('csv', $preview['type']);
        $this->assertSame(['sku', 'qty', 'ean'], $preview['headers']);
        $this->assertNotEmpty($preview['preview_rows']);
    }

    public function test_saving_csv_mapping_persists_canonical_config(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = Supplier::query()->create([
            'name' => 'Mapped CSV',
            'code' => 'mapped-csv',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/mapped.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'sync_enabled' => false,
            'config' => [
                'csv_delimiter' => 'comma',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->fillForm([
                'config' => [
                    'csv_sku_column' => 'CODICE',
                    'csv_stock_column' => 'QTA',
                    'matching_strategy' => 'sku_global',
                    'require_vendor_scope' => false,
                    'vendor_scope' => [],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier->refresh();
        $this->assertSame('CODICE', SupplierCsvConfig::skuColumn($supplier));
        $this->assertSame('QTA', SupplierCsvConfig::stockColumn($supplier));
    }

    public function test_dry_run_shows_stats_and_does_not_mutate_supplier_products(): void
    {
        $this->createVariant('DRY-1', 'Vendor');

        $supplier = Supplier::query()->create([
            'name' => 'Dry CSV',
            'code' => 'dry-csv',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/dry.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'sync_enabled' => true,
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
            ],
        ]);

        Http::fake([
            'https://supplier.example/dry.csv' => Http::response("sku,qty\nDRY-1,3\nMISSING-9,1\n"),
        ]);

        $result = app(SupplierDryRunService::class)->run($supplier);

        $this->assertNull($result['error']);
        $this->assertFalse($result['mutated']);
        $this->assertSame(2, $result['stats']['parsed']);
        $this->assertSame(1, $result['stats']['matched']);
        $this->assertSame(1, $result['stats']['unmatched']);
        $this->assertNotEmpty($result['matched_examples']);
        $this->assertNotEmpty($result['unmatched_examples']);
        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_can_create_xml_url_supplier_with_paths(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Acme XML',
                'code' => 'acme-xml',
                'enabled' => true,
                'sync_enabled' => true,
                'connector_type' => Supplier::CONNECTOR_XML_URL,
                'endpoint_url' => 'https://supplier.example/acme.xml',
                'auth_type' => Supplier::AUTH_NONE,
                'config' => [
                    'response_type' => 'xml',
                    'xml_item_path' => '//item',
                    'xml_sku_path' => 'sku',
                    'xml_stock_path' => 'qty',
                    'matching_strategy' => 'sku_global',
                    'match_by_barcode' => false,
                    'require_vendor_scope' => false,
                    'vendor_scope' => [],
                ],
                'availability_fallback_quantity' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', 'acme-xml')->sole();
        $this->assertSame('//item', data_get($supplier->config, 'xml_item_path'));
        $this->assertSame('sku', data_get($supplier->config, 'xml_sku_path'));
        $this->assertSame('qty', data_get($supplier->config, 'xml_stock_path'));
    }

    public function test_can_create_json_api_supplier_with_data_path(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Acme JSON',
                'code' => 'acme-json',
                'enabled' => true,
                'sync_enabled' => true,
                'connector_type' => Supplier::CONNECTOR_JSON_API,
                'endpoint_url' => 'https://supplier.example/acme.json',
                'auth_type' => Supplier::AUTH_BEARER_TOKEN,
                'credential_token' => 'secret-token',
                'config' => [
                    'response_type' => 'json',
                    'method' => 'GET',
                    'json_data_path' => 'data.items',
                    'json_sku_path' => 'sku',
                    'json_stock_path' => 'stock',
                    'matching_strategy' => 'sku_global',
                    'require_vendor_scope' => false,
                    'vendor_scope' => [],
                ],
                'availability_fallback_quantity' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', 'acme-json')->sole();
        $this->assertSame(Supplier::CONNECTOR_JSON_API, $supplier->connector_type);
        $this->assertSame('data.items', data_get($supplier->config, 'json_data_path'));
        $this->assertSame('secret-token', data_get($supplier->credentials, 'token'));
        $this->assertNull(data_get($supplier->config, 'token'));
        $this->assertNull(data_get($supplier->config, 'password'));
    }

    public function test_credentials_are_encrypted_and_not_stored_in_config(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Secure',
            'code' => 'secure',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/secure.csv',
            'auth_type' => Supplier::AUTH_BASIC,
            'credentials' => [
                'username' => 'user',
                'password' => 'secret-pass',
            ],
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
            ],
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('suppliers')->where('id', $supplier->id)->value('credentials');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-pass', $raw);
        $this->assertSame('secret-pass', $supplier->fresh()->credentials['password']);
        $this->assertStringNotContainsString('secret-pass', json_encode($supplier->fresh()->config));
    }

    public function test_enabling_supplier_requires_valid_mapping(): void
    {
        $errors = app(SupplierOnboardingValidator::class)->validateForSync(new Supplier([
            'code' => 'bad-csv',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/bad.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'config' => [],
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn (string $error): bool => str_contains($error, 'SKU')));
    }

    public function test_queue_sync_now_dispatches_job_and_prevents_duplicate(): void
    {
        Queue::fake();

        $supplier = Supplier::query()->create([
            'name' => 'Queue Me',
            'code' => 'queue-me',
            'enabled' => true,
            'sync_enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/q.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
            ],
        ]);

        $dispatcher = app(\App\Services\Sync\MarketplaceJobDispatcher::class);
        $first = $dispatcher->dispatchSupplierSync('queue-me');
        $this->assertTrue($first->dispatched);

        // Simulate active lock so second dispatch is blocked.
        $lock = \App\Services\Sync\MarketplaceJobLock::make(
            \App\Services\Sync\MarketplaceJobLock::forSupplier('queue-me'),
        );
        $this->assertTrue($lock->get());

        $second = $dispatcher->dispatchSupplierSync('queue-me');
        $this->assertTrue($second->alreadyRunning);

        $lock->release();

        Queue::assertPushed(SyncSupplierStockJob::class, 1);
    }

    public function test_supplier_appears_in_daily_sync_when_enabled_and_due(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Due CSV',
            'code' => 'due-onboard',
            'enabled' => true,
            'sync_enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://supplier.example/due-onboard.csv',
            'auth_type' => Supplier::AUTH_NONE,
            'force_daily_sync' => true,
            'config' => [
                'csv_sku_column' => 'sku',
                'csv_stock_column' => 'qty',
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
            ],
        ]);

        $enabled = app(SupplierSyncManager::class)->enabledSuppliers();
        $this->assertTrue($enabled->contains(fn (Supplier $row): bool => $row->code === $supplier->code));
        $this->assertTrue(app(SupplierSyncManager::class)->isDueForSync($supplier));
    }

    public function test_setup_commands_do_not_overwrite_dashboard_created_suppliers(): void
    {
        Supplier::query()->create([
            'name' => 'Dashboard Prezioso',
            'code' => Supplier::CODE_PREZIOSO,
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'https://custom.example/prezioso.csv',
            'auth_type' => Supplier::AUTH_NTLM,
            'sync_enabled' => true,
            'config' => [
                'csv_sku_column' => 'CODICE',
                'csv_stock_column' => 'QTA',
                'matching_strategy' => 'sku_global',
            ],
        ]);

        $this->artisan('supplier:setup-prezioso')->assertSuccessful();

        $supplier = Supplier::query()->where('code', Supplier::CODE_PREZIOSO)->sole();
        $this->assertSame('https://custom.example/prezioso.csv', $supplier->endpoint_url);
        $this->assertSame('Dashboard Prezioso', $supplier->name);
        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('QTA', data_get($supplier->config, 'csv_stock_column'));
    }

    public function test_code_from_name_helper(): void
    {
        $this->assertSame('acme_knives', SupplierFormDataFactory::codeFromName('Acme Knives'));
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
}
