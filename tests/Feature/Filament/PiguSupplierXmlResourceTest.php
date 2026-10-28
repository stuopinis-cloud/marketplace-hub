<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PiguSupplierXml\Pages\CreatePiguSupplierXml;
use App\Filament\Resources\PiguSupplierXml\Pages\ListPiguSupplierXml;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PiguSupplierXmlResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_is_accessible(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ListPiguSupplierXml::class)
            ->assertSuccessful();
    }

    public function test_create_saves_pigu_offers_config(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreatePiguSupplierXml::class)
            ->fillForm([
                'name' => 'Test XML Tiekėjas',
                'code' => 'test_xml_tiekejas',
                'endpoint_url' => 'https://example.test/catalog.xml',
                'auth_type' => Supplier::AUTH_NONE,
                'enabled' => true,
                'sync_enabled' => true,
                'force_daily_sync' => true,
                'stale_after_minutes' => 2880,
                'config' => [
                    'xml_item_path' => '//product',
                    'xml_sku_path' => 'sku',
                    'xml_barcode_path' => 'ean',
                    'xml_stock_path' => 'stock',
                    'xml_price_path' => 'price',
                    'xml_title_path' => 'title',
                    'pigu_offers' => [
                        'enabled' => true,
                        'export_supplier_only' => true,
                        'collection_hours' => 240,
                        'price_source' => 'recommended_retail',
                        'price_includes_vat' => false,
                        'vat_rate' => 21,
                        'price_multiplier' => 1,
                        'price_rounding' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', 'test_xml_tiekejas')->first();

        $this->assertNotNull($supplier);
        $this->assertSame(Supplier::CONNECTOR_XML_URL, $supplier->connector_type);
        $this->assertTrue(PiguSupplierOfferConfig::isEnabled($supplier));
        $this->assertSame(240, PiguSupplierOfferConfig::collectionHours($supplier));
        $this->assertSame('sku', $supplier->config['xml_sku_path'] ?? null);
        $this->assertSame('ean', $supplier->config['xml_barcode_path'] ?? null);
    }
}
