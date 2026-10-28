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

    public function test_create_persists_google_merchant_field_mappings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreatePiguSupplierXml::class)
            ->fillForm([
                'name' => 'M-Tac Google',
                'code' => 'mtac_google_map',
                'endpoint_url' => 'https://example.test/mtac-atom.xml',
                'auth_type' => Supplier::AUTH_NONE,
                'enabled' => true,
                'sync_enabled' => true,
                'availability_fallback_quantity' => 5,
                'pigu_item_path_candidates' => [
                    ['path' => '//*[local-name()="entry"]', 'count' => 9150, 'label' => 'entry — 9150 produktų'],
                ],
                'pigu_item_count' => 9150,
                'pigu_available_fields' => ['g:id', 'g:gtin', 'g:availability', 'g:price', 'g:title'],
                'config' => [
                    'xml_item_path' => '//*[local-name()="entry"]',
                    'xml_sku_path' => 'g:id',
                    'xml_barcode_path' => 'g:gtin',
                    'xml_stock_path' => 'g:availability',
                    'xml_availability_path' => 'g:availability',
                    'xml_price_path' => 'g:price',
                    'xml_title_path' => 'g:title',
                    'xml_namespaces' => [
                        'atom' => 'http://www.w3.org/2005/Atom',
                        'g' => 'http://base.google.com/ns/1.0',
                    ],
                    'pigu_offers' => [
                        'enabled' => true,
                        'export_supplier_only' => true,
                        'collection_hours' => 240,
                        'price_source' => 'recommended_retail',
                        'price_includes_vat' => true,
                        'vat_rate' => 21,
                        'price_multiplier' => 1,
                        'price_rounding' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', 'mtac_google_map')->first();

        $this->assertNotNull($supplier);
        $this->assertSame('g:id', $supplier->config['xml_sku_path'] ?? null);
        $this->assertSame('g:gtin', $supplier->config['xml_barcode_path'] ?? null);
        $this->assertSame('g:availability', $supplier->config['xml_stock_path'] ?? null);
        $this->assertSame('g:price', $supplier->config['xml_price_path'] ?? null);
        $this->assertSame('g:title', $supplier->config['xml_title_path'] ?? null);
        $this->assertSame(5, $supplier->availability_fallback_quantity);
        $this->assertTrue((bool) data_get($supplier->config, 'pigu_offers.price_includes_vat'));
        $this->assertSame('http://base.google.com/ns/1.0', data_get($supplier->config, 'xml_namespaces.g'));
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
