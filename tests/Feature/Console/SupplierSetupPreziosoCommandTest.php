<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSetupPreziosoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_prezioso_supplier_without_password_in_config(): void
    {
        config([
            'services.prezioso.ntlm_username' => 'preziosoexport',
            'services.prezioso.ntlm_password' => 'env-secret',
        ]);

        $this->artisan('supplier:setup-prezioso')
            ->expectsOutputToContain('Prezioso supplier is configured')
            ->assertSuccessful();

        $supplier = Supplier::query()->where('code', Supplier::CODE_PREZIOSO)->sole();

        $this->assertSame(Supplier::CONNECTOR_CSV_URL, $supplier->connector_type);
        $this->assertSame(Supplier::AUTH_NTLM, $supplier->auth_type);
        $this->assertSame('http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV', $supplier->endpoint_url);
        $this->assertSame('csv', data_get($supplier->config, 'response_type'));
        $this->assertSame('auto', data_get($supplier->config, 'csv_delimiter'));
        $this->assertSame('sku_global', data_get($supplier->config, 'matching_strategy'));
        $this->assertFalse((bool) data_get($supplier->config, 'match_by_barcode'));
        $this->assertFalse((bool) data_get($supplier->config, 'require_vendor_scope'));
        $this->assertSame([], data_get($supplier->config, 'vendor_scope'));
        $this->assertNull(data_get($supplier->config, 'password'));
        $this->assertNull(data_get($supplier->config, 'ntlm_password'));
        $this->assertStringNotContainsString('env-secret', json_encode($supplier->config));
        $this->assertTrue($supplier->enabled);
        $this->assertContains(Supplier::AUTH_NTLM, [
            Supplier::AUTH_NONE,
            Supplier::AUTH_BASIC,
            Supplier::AUTH_BEARER_TOKEN,
            Supplier::AUTH_CUSTOM_HEADERS,
            Supplier::AUTH_NTLM,
        ]);
    }

    public function test_setup_preserves_existing_column_mappings_on_rerun(): void
    {
        Supplier::query()->create([
            'name' => 'Prezioso',
            'code' => Supplier::CODE_PREZIOSO,
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV',
            'auth_type' => Supplier::AUTH_NTLM,
            'sync_enabled' => true,
            'config' => [
                'csv_sku_column' => 'CODICE',
                'csv_stock_column' => 'QTA',
                'csv_barcode_column' => 'EAN',
                'matching_strategy' => 'sku_global',
            ],
        ]);

        $this->artisan('supplier:setup-prezioso')->assertSuccessful();

        $supplier = Supplier::query()->where('code', Supplier::CODE_PREZIOSO)->sole();

        $this->assertSame('CODICE', data_get($supplier->config, 'csv_sku_column'));
        $this->assertSame('QTA', data_get($supplier->config, 'csv_stock_column'));
        $this->assertSame('EAN', data_get($supplier->config, 'csv_barcode_column'));
    }
}
