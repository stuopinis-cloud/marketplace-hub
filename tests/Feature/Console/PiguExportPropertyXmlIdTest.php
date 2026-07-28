<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\PiguCategoryField;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguExportPropertyXmlIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_name_is_used_in_xml_id_even_when_numeric_property_id_exists(): void
    {
        $this->seedFlashlightProduct();

        PiguCategoryField::query()->create([
            'lowest_category_id' => 4757,
            'field_id' => 91447,
            'field_title' => 'Prekės ženklas',
            'field_title_en' => 'Brand',
            'mandatory' => true,
            'field_value_type' => 'list',
        ]);

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'zibintuveliai',
            'property_name' => 'Prekės ženklas',
            'property_id' => 91447,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_VENDOR,
            'value_name' => null,
            'enabled' => true,
            'priority' => 100,
        ]);

        $xml = Storage::disk('public')->get(
            app(PiguProductFeedExporter::class)->export(new PiguExportOptions(easyOnly: false))['feed_path'],
        );

        $this->assertStringContainsString('<id><![CDATA[Prekės ženklas]]></id>', $xml);
        $this->assertStringContainsString('<value><![CDATA[Nitecore]]></value>', $xml);
        $this->assertStringNotContainsString('<id><![CDATA[91447]]></id>', $xml);
    }

    public function test_vendor_rule_exports_prekes_zenklas_as_id(): void
    {
        $this->seedFlashlightProduct();

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'zibintuveliai',
            'property_name' => 'Prekės ženklas',
            'property_id' => 91447,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_VENDOR,
            'enabled' => true,
            'priority' => 100,
        ]);

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertMatchesRegularExpression(
            '/<property>\s*<id><!\[CDATA\[Prekės ženklas\]\]><\/id>\s*<values>\s*<value><!\[CDATA\[Nitecore\]\]><\/value>/u',
            $xml,
        );
    }

    public function test_fixed_tipas_rule_exports_tipas_as_id(): void
    {
        $this->seedFlashlightProduct();

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'zibintuveliai',
            'property_name' => 'Tipas',
            'property_id' => 90001,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_FIXED,
            'value_name' => 'Žibintuvėliai',
            'enabled' => true,
            'priority' => 100,
        ]);

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertMatchesRegularExpression(
            '/<property>\s*<id><!\[CDATA\[Tipas\]\]><\/id>\s*<values>\s*<value><!\[CDATA\[Žibintuvėliai\]\]><\/value>/u',
            $xml,
        );
        $this->assertStringNotContainsString('<id><![CDATA[90001]]></id>', $xml);
    }

    public function test_numeric_field_ids_do_not_appear_when_property_name_exists(): void
    {
        $this->seedFlashlightProduct();

        PiguCategoryField::query()->create([
            'lowest_category_id' => 4757,
            'field_id' => 91447,
            'field_title' => 'Prekės ženklas',
            'field_title_en' => 'Brand',
            'mandatory' => false,
            'field_value_type' => 'list',
        ]);

        PiguCategoryField::query()->create([
            'lowest_category_id' => 4757,
            'field_id' => 90001,
            'field_title' => 'Tipas',
            'field_title_en' => 'Type',
            'mandatory' => false,
            'field_value_type' => 'list',
        ]);

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'zibintuveliai',
            'property_name' => 'Prekės ženklas',
            'property_id' => 91447,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_VENDOR,
            'enabled' => true,
            'priority' => 50,
        ]);

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'zibintuveliai',
            'property_name' => 'Tipas',
            'property_id' => 90001,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_FIXED,
            'value_name' => 'Žibintuvėliai',
            'enabled' => true,
            'priority' => 60,
        ]);

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        preg_match_all('/<property>\s*<id><!\[CDATA\[(.*?)\]\]><\/id>/us', $xml, $matches);
        $ids = $matches[1] ?? [];

        $this->assertContains('Prekės ženklas', $ids);
        $this->assertContains('Tipas', $ids);
        $this->assertNotContains('91447', $ids);
        $this->assertNotContains('90001', $ids);
    }

    private function seedFlashlightProduct(): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            ['name' => 'Pigu / PHH', 'enabled' => true, 'config' => ['require_category_mapping' => true]],
        );

        $collection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'ext-zibintuveliai',
            'name' => 'Žibintuvėliai',
            'handle' => 'zibintuveliai',
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'zibintuveliai',
            'target_category_id' => '4757',
            'target_category_name' => 'Žibintuvėliai',
            'target_category_path' => '4757|Žibintuvėliai',
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-flashlight-'.uniqid(),
            'title' => 'Nitecore Flashlight',
            'description_html' => '<p>Bright flashlight</p>',
            'vendor' => 'Nitecore',
            'product_type' => 'Flashlights',
            'status' => ProductStatus::Active,
            'handle' => 'nitecore-flashlight',
            'imported_at' => now(),
        ]);
        $product->sourceCategories()->attach($collection->id);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/flashlight.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-flashlight-'.uniqid(),
            'sku' => 'SKU-FLASH',
            'barcode' => '5901234123888',
            'title' => 'Default Title',
            'price' => 29.99,
            'image_url' => 'https://cdn.example.com/flashlight.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 5,
        ]);

        return $product->fresh(['sourceCategories', 'variants', 'images']);
    }
}
