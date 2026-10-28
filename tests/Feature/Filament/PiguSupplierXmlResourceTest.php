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

    public function test_create_saves_custom_entry_item_path(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreatePiguSupplierXml::class)
            ->fillForm([
                'name' => 'M-Tac XML',
                'code' => 'mtac_xml_entry',
                'endpoint_url' => 'https://example.test/mtac.xml',
                'auth_type' => Supplier::AUTH_NONE,
                'enabled' => true,
                'sync_enabled' => true,
                'force_daily_sync' => true,
                'stale_after_minutes' => 2880,
                'pigu_item_path_candidates' => [
                    ['path' => '//entry', 'count' => 12, 'label' => 'entry — 12 produktų'],
                ],
                'pigu_item_count' => 12,
                'config' => [
                    'xml_item_path' => '//entry',
                    'xml_sku_path' => 'sku',
                    'xml_barcode_path' => 'ean',
                    'xml_stock_path' => 'stock',
                    'xml_price_path' => 'price',
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

        $supplier = Supplier::query()->where('code', 'mtac_xml_entry')->first();

        $this->assertNotNull($supplier);
        $this->assertSame('//entry', $supplier->config['xml_item_path'] ?? null);
    }

    public function test_zero_match_item_path_shows_lithuanian_validation_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreatePiguSupplierXml::class)
            ->fillForm([
                'name' => 'Bad Path XML',
                'code' => 'bad_path_xml',
                'endpoint_url' => 'https://example.test/bad.xml',
                'auth_type' => Supplier::AUTH_NONE,
                'enabled' => true,
                'sync_enabled' => true,
                'pigu_item_path_candidates' => [
                    ['path' => '//entry', 'count' => 5, 'label' => 'entry — 5 produktai'],
                    ['path' => '//product', 'count' => 0, 'label' => 'product — 0 produktų'],
                ],
                'pigu_item_count' => 0,
                'config' => [
                    'xml_item_path' => '//product',
                    'xml_sku_path' => 'sku',
                    'xml_barcode_path' => 'ean',
                    'xml_stock_path' => 'stock',
                    'xml_price_path' => 'price',
                    'pigu_offers' => [
                        'enabled' => false,
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
            ->assertHasFormErrors(['config.xml_item_path']);
    }
}
