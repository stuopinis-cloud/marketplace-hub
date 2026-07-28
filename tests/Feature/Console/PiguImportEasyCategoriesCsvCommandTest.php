<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\Pigu\PiguEasyCategoriesCsvImporter;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguImportEasyCategoriesCsvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_category_mapping_dimension_and_property_rules(): void
    {
        $path = $this->writeCsv($this->csvHeader()."\n".<<<'CSV'
Administraciniai dėklai,administraciniai-deklai,15697,Kitas turistinis inventorius,2,"Prekės ženklas: Vendor | Tipas: Krepšeliai",true,0.30,0.25,0.18,0.06
CSV);

        $this->artisan('pigu:import-easy-categories-csv', ['path' => $path])
            ->expectsOutputToContain('Rows importable: 1')
            ->assertSuccessful();

        $channel = MarketplaceChannel::query()->where('type', 'pigu')->firstOrFail();

        $this->assertDatabaseHas('category_mappings', [
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'administraciniai-deklai',
            'target_category_id' => '15697',
            'target_category_name' => 'Kitas turistinis inventorius',
            'target_category_path' => '15697|Kitas turistinis inventorius',
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 100,
        ]);

        $this->assertDatabaseHas('pigu_dimension_rules', [
            'match_type' => 'collection',
            'match_value' => 'administraciniai-deklai',
            'weight_kg' => 0.3,
            'length_m' => 0.25,
            'width_m' => 0.18,
            'height_m' => 0.06,
            'enabled' => true,
            'priority' => 100,
        ]);

        $this->assertDatabaseHas('pigu_property_rules', [
            'pigu_category_id' => 15697,
            'match_type' => 'collection',
            'match_value' => 'administraciniai-deklai',
            'property_name' => 'Prekės ženklas',
            'value_source' => 'vendor',
            'value_name' => null,
        ]);

        $this->assertDatabaseHas('pigu_property_rules', [
            'pigu_category_id' => 15697,
            'match_value' => 'administraciniai-deklai',
            'property_name' => 'Tipas',
            'value_source' => 'fixed',
            'value_name' => 'Krepšeliai',
        ]);
    }

    public function test_skips_export_enabled_false(): void
    {
        $path = $this->writeCsv($this->csvHeader()."\n".<<<'CSV'
Skip Me,skip-me,15697,Kitas,1,"Tipas: X",false,0.30,0.25,0.18,0.06
CSV);

        $result = app(PiguEasyCategoriesCsvImporter::class)->import($path);

        $this->assertSame(1, $result['rows_read']);
        $this->assertSame(0, $result['rows_importable']);
        $this->assertSame(1, $result['rows_skipped']);
        $this->assertSame(0, CategoryMapping::query()->count());
    }

    public function test_dry_run_warns_duplicate_handle_and_does_not_write(): void
    {
        $path = $this->writeCsv($this->csvHeader()."\n".<<<'CSV'
First,same-handle,111,First Cat,1,"Tipas: A",true,0.10,0.10,0.10,0.10
Second,same-handle,222,Second Cat,1,"Tipas: B",true,0.20,0.20,0.20,0.20
CSV);

        Artisan::call('pigu:import-easy-categories-csv', [
            'path' => $path,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('dry-run', mb_strtolower($output));
        $this->assertStringContainsString('same-handle', $output);
        $this->assertStringContainsString('line 2 loses to line 3', $output);
        $this->assertSame(0, CategoryMapping::query()->count());
        $this->assertSame(0, PiguDimensionRule::query()->count());
        $this->assertSame(0, PiguPropertyRule::query()->count());
    }

    public function test_last_duplicate_handle_wins_on_real_import(): void
    {
        $path = $this->writeCsv($this->csvHeader()."\n".<<<'CSV'
First,same-handle,111,First Cat,1,"Tipas: A",true,0.10,0.10,0.10,0.10
Second,same-handle,222,Second Cat,1,"Tipas: B",true,0.20,0.20,0.20,0.20
CSV);

        app(PiguEasyCategoriesCsvImporter::class)->import($path);

        $this->assertSame(1, CategoryMapping::query()->count());
        $this->assertDatabaseHas('category_mappings', [
            'source_value' => 'same-handle',
            'target_category_id' => '222',
            'target_category_name' => 'Second Cat',
        ]);
        $this->assertDatabaseHas('pigu_dimension_rules', [
            'match_value' => 'same-handle',
            'weight_kg' => 0.2,
        ]);
    }

    public function test_full_pigu_export_uses_imported_rules(): void
    {
        $path = $this->writeCsv($this->csvHeader()."\n".<<<'CSV'
Administraciniai dėklai,administraciniai-deklai,15697,Kitas turistinis inventorius,2,"Prekės ženklas: Vendor | Tipas: Krepšeliai",true,0.30,0.25,0.18,0.06
CSV);

        app(PiguEasyCategoriesCsvImporter::class)->import($path);

        $source = Source::query()->create(['type' => 'shopify', 'name' => 'Shopify', 'enabled' => true, 'config' => []]);
        $collection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => '100',
            'name' => 'Administraciniai dėklai',
            'handle' => 'administraciniai-deklai',
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'easy-1',
            'title' => 'Admin Case',
            'description_html' => '<p>Case description</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Cases',
            'status' => ProductStatus::Active,
            'handle' => 'admin-case',
            'imported_at' => now(),
        ]);
        $product->sourceCategories()->attach($collection->id);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/case.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'var-easy-1',
            'sku' => 'CASE-1',
            'barcode' => '5901234123888',
            'title' => 'Default Title',
            'price' => 12.5,
            'image_url' => 'https://cdn.example.com/case.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 3,
        ]);

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['exported_products']);
        $this->assertStringContainsString('<category-id>15697</category-id>', $xml);
        $this->assertStringContainsString('<category-name><![CDATA[Kitas turistinis inventorius]]></category-name>', $xml);
        $this->assertStringContainsString('<weight>0.3</weight>', $xml);
        $this->assertStringContainsString('<length>0.25</length>', $xml);
        $this->assertStringContainsString('<width>0.18</width>', $xml);
        $this->assertStringContainsString('<height>0.06</height>', $xml);
        $this->assertStringContainsString('<![CDATA[BrandCo]]>', $xml);
        $this->assertStringContainsString('<![CDATA[Krepšeliai]]>', $xml);
    }

    public function test_parse_fill_values_vendor_and_fixed(): void
    {
        $importer = app(PiguEasyCategoriesCsvImporter::class);
        $parsed = $importer->parseFillValues('Prekės ženklas: Vendor | Tipas: Krepšeliai');

        $this->assertSame([
            [
                'property_name' => 'Prekės ženklas',
                'value_source' => 'vendor',
                'value_name' => null,
            ],
            [
                'property_name' => 'Tipas',
                'value_source' => 'fixed',
                'value_name' => 'Krepšeliai',
            ],
        ], $parsed);
    }

    private function writeCsv(string $contents): string
    {
        $path = storage_path('app/pigu-easy-categories-'.uniqid().'.csv');
        file_put_contents($path, $contents);

        return $path;
    }

    private function csvHeader(): string
    {
        return 'Shopify kategorija,Shopify handle,Pigu kategorijų ID,Pigu kategorijos,Privalomų laukų skaičius,Užpildomos reikšmės,export_enabled,"Preliminarus svoris, kg","Ilgis, m","Plotis, m","Aukštis, m"';
    }
}
