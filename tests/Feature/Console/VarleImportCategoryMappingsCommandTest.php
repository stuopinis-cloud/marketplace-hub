<?php

namespace Tests\Feature\Console;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VarleImportCategoryMappingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_import_works(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"Apranga, avalynė, aksesuarai"
CSV);

        $this->artisan('varle:import-category-mappings', [
            'path' => $path,
            '--channel' => 'varle',
            '--priority' => '100',
        ])
            ->expectsOutputToContain('Import complete.')
            ->assertSuccessful();

        $this->assertDatabaseHas('category_mappings', [
            'marketplace_channel_id' => $channel->id,
            'source_value' => 'apranga',
            'target_category_path' => 'Apranga, avalynė, aksesuarai',
        ]);
    }

    public function test_cli_dry_run_makes_no_database_changes(): void
    {
        MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        $path = $this->writeCsv(<<<'CSV'
shopify_collection,shopify_handle,varle_final_category
APRANGA,apranga,"Apranga, avalynė, aksesuarai"
CSV);

        $this->artisan('varle:import-category-mappings', [
            'path' => $path,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run complete.')
            ->assertSuccessful();

        $this->assertSame(0, CategoryMapping::query()->count());
    }

    private function writeCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'category-mapping-cli-');
        $csvPath = $path.'.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, $contents);

        return $csvPath;
    }
}
