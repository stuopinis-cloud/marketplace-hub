<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierCsvMappingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_sku_mapping_persists_csv_sku_column(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = $this->makePreziosoSupplier([
            'csv_sku_column' => null,
            'csv_stock_column' => null,
            'matching_strategy' => 'sku_global',
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->fillForm([
                'config' => [
                    'csv_sku_column' => 'CODICE',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier->refresh();

        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('CODICE', SupplierCsvConfig::skuColumn($supplier));
    }

    public function test_saving_stock_mapping_persists_csv_stock_column(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = $this->makePreziosoSupplier([
            'csv_sku_column' => 'CODICE',
            'csv_stock_column' => null,
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->fillForm([
                'config' => [
                    'csv_sku_column' => 'CODICE',
                    'csv_stock_column' => 'QTA',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier->refresh();

        $this->assertSame('QTA', data_get($supplier->config, 'csv_stock_column'));
        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('QTA', SupplierCsvConfig::stockColumn($supplier));
    }

    public function test_saving_unrelated_fields_preserves_existing_csv_mappings(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = $this->makePreziosoSupplier([
            'csv_sku_column' => 'CODICE',
            'csv_stock_column' => 'QTA',
            'csv_barcode_column' => 'EAN',
            'keep_me' => 'yes',
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->fillForm([
                'name' => 'Prezioso Updated',
                'config' => [
                    'csv_sku_column' => 'CODICE',
                    'csv_stock_column' => 'QTA',
                    'csv_barcode_column' => 'EAN',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier->refresh();

        $this->assertSame('Prezioso Updated', $supplier->name);
        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('QTA', data_get($supplier->config, 'csv_stock_column'));
        $this->assertSame('EAN', data_get($supplier->config, 'csv_barcode_column'));
        $this->assertSame('yes', data_get($supplier->config, 'keep_me'));
    }

    public function test_rerunning_setup_prezioso_preserves_existing_csv_column_mappings(): void
    {
        $supplier = $this->makePreziosoSupplier([
            'csv_sku_column' => 'CODICE',
            'csv_stock_column' => 'QTA',
            'csv_barcode_column' => 'EAN',
            'custom_note' => 'keep',
        ]);

        $this->artisan('supplier:setup-prezioso')
            ->expectsOutputToContain('Prezioso supplier is configured')
            ->assertSuccessful();

        $supplier->refresh();

        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('QTA', data_get($supplier->config, 'csv_stock_column'));
        $this->assertSame('EAN', data_get($supplier->config, 'csv_barcode_column'));
        $this->assertSame('keep', data_get($supplier->config, 'custom_note'));
        $this->assertSame('auto', data_get($supplier->config, 'csv_delimiter'));
        $this->assertSame('sku_global', data_get($supplier->config, 'matching_strategy'));
    }

    public function test_supplier_sync_prezioso_dry_run_sees_saved_mapping(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = $this->makePreziosoSupplier([
            'csv_sku_column' => null,
            'csv_stock_column' => null,
        ]);

        // Use HTTP client auth so the dry-run can be faked without NTLM/cURL.
        $supplier->update([
            'auth_type' => Supplier::AUTH_NONE,
            'endpoint_url' => 'https://supplier.example/prezioso.csv',
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->fillForm([
                'config' => [
                    'csv_sku_column' => 'CODICE',
                    'csv_stock_column' => 'QTA',
                    'csv_barcode_column' => 'EAN',
                    'matching_strategy' => 'sku_global',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier->refresh();
        $this->assertSame('CODICE', SupplierCsvConfig::skuColumn($supplier));
        $this->assertSame('QTA', SupplierCsvConfig::stockColumn($supplier));

        Http::fake([
            'https://supplier.example/prezioso.csv' => Http::response("CODICE;EAN;QTA\nPREZ-1;5901234123457;12\n"),
        ]);

        $this->artisan('supplier:sync', ['supplier' => 'prezioso', '--dry-run' => true])
            ->expectsOutputToContain('Parsed: 1')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makePreziosoSupplier(array $config): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Prezioso',
            'code' => Supplier::CODE_PREZIOSO,
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV',
            'auth_type' => Supplier::AUTH_NTLM,
            'stock_priority' => 100,
            'in_stock_delivery_text' => '5-10 d.d.',
            'allow_backorder_export' => false,
            'availability_fallback_quantity' => 5,
            'sync_enabled' => true,
            'config' => array_merge([
                'csv_delimiter' => 'auto',
                'csv_encoding' => 'auto',
                'csv_has_header' => true,
                'matching_strategy' => 'sku_global',
                'require_vendor_scope' => false,
                'vendor_scope' => [],
                'missing_from_feed_policy' => 'mark_unavailable',
            ], $config),
        ]);
    }
}
