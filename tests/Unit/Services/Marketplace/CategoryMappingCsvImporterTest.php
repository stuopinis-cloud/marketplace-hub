<?php

namespace Tests\Unit\Services\Marketplace;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Services\Marketplace\CategoryMappingCsvImportOptions;
use App\Services\Marketplace\CategoryMappingCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryMappingCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private CategoryMappingCsvImporter $importer;

    private MarketplaceChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->importer = $this->app->make(CategoryMappingCsvImporter::class);
        $this->channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);
    }

    public function test_successful_import_creates_mappings_from_handle(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"Apranga, avalynė, aksesuarai"
VYRAMS,vyrams,"Apranga vyrams"
CSV);

        $result = $this->importer->import($path, $this->importOptions());

        $this->assertSame(2, $result->totalRows);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertDatabaseHas('category_mappings', [
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'collection',
            'source_value' => 'apranga',
            'target_category_path' => 'Apranga, avalynė, aksesuarai',
        ]);
        $this->assertDatabaseHas('category_mappings', [
            'source_value' => 'vyrams',
            'target_category_path' => 'Apranga vyrams',
        ]);
    }

    public function test_existing_mapping_gets_updated(): void
    {
        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'collection',
            'source_value' => 'apranga',
            'target_category_path' => 'Old category',
            'priority' => 100,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"Apranga, avalynė, aksesuarai"
CSV);

        $result = $this->importer->import($path, $this->importOptions());

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, CategoryMapping::query()->count());
        $this->assertSame(
            'Apranga, avalynė, aksesuarai',
            CategoryMapping::query()->value('target_category_path'),
        );
    }

    public function test_duplicate_source_value_does_not_create_duplicate_rows(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"First category"
APRANGA DUPLICATE,apranga,"Second category"
CSV);

        $this->importer->import($path, $this->importOptions());

        $this->assertSame(1, CategoryMapping::query()->count());
        $this->assertSame('Second category', CategoryMapping::query()->value('target_category_path'));
    }

    public function test_empty_handle_is_skipped(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,,"Should be skipped"
CSV);

        $result = $this->importer->import($path, $this->importOptions());

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, CategoryMapping::query()->count());
    }

    public function test_empty_varle_category_is_skipped(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,
CSV);

        $result = $this->importer->import($path, $this->importOptions());

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, CategoryMapping::query()->count());
    }

    public function test_utf8_lithuanian_characters_are_preserved(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
Striukės vyrams,striukes-vyrams,"Striukės vyrams"
CSV);

        $this->importer->import($path, $this->importOptions());

        $this->assertSame('Striukės vyrams', CategoryMapping::query()->value('target_category_path'));
    }

    public function test_dry_run_makes_no_database_changes(): void
    {
        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"Apranga, avalynė, aksesuarai"
CSV);

        $result = $this->importer->import($path, $this->importOptions(dryRun: true));

        $this->assertTrue($result->dryRun);
        $this->assertSame(1, $result->created);
        $this->assertSame(0, CategoryMapping::query()->count());
    }

    public function test_preview_returns_first_twenty_rows(): void
    {
        $rows = ["shopify_collection,shopify_handle,varle_final_category"];

        for ($index = 1; $index <= 25; $index++) {
            $rows[] = "Collection {$index},handle-{$index},\"Category {$index}\"";
        }

        $path = $this->writeCsv(implode("\n", $rows));
        $plan = $this->importer->plan($this->importer->parse($path), $this->importOptions());

        $this->assertSame(25, $plan->totalRows);
        $this->assertCount(20, $plan->previewRows);
    }

    private function importOptions(bool $dryRun = false): CategoryMappingCsvImportOptions
    {
        return new CategoryMappingCsvImportOptions(
            marketplaceChannelId: $this->channel->id,
            sourceType: 'collection',
            priority: 100,
            enabled: true,
            exportEnabled: true,
            dryRun: $dryRun,
        );
    }

    private function writeCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'category-mapping-import-');
        $csvPath = $path.'.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, $contents);

        return $csvPath;
    }
}
