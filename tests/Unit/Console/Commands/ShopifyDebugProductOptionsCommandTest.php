<?php

namespace Tests\Unit\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class ShopifyDebugProductOptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prints_option_names_and_values_for_handle(): void
    {
        VarleCatalogFixtures::createColorSizeProduct([
            'handle' => 'debug-handle',
            'raw_payload' => [
                'totalVariants' => 3,
                'options' => [
                    ['name' => 'Spalva', 'values' => ['Mėlyni', 'Juodi']],
                    ['name' => 'Dydis', 'values' => ['M', 'L']],
                ],
            ],
        ]);

        $this->artisan('shopify:debug-product-options', ['handle' => 'debug-handle'])
            ->expectsOutputToContain('Product handle: debug-handle')
            ->expectsOutputToContain('Local variant count: 3')
            ->expectsOutputToContain('Detected option names: Spalva, Dydis')
            ->expectsOutputToContain('option1_name: Spalva')
            ->expectsOutputToContain('option1_value: Mėlyni')
            ->expectsOutputToContain('option2_name: Dydis')
            ->expectsOutputToContain('option2_value: M')
            ->expectsOutputToContain('image_url: yes')
            ->assertSuccessful();
    }

    public function test_command_shows_variant_image_url_in_verbose_mode(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'verbose-image-handle'],
            variantOverrides: ['image_url' => 'https://cdn.example.com/verbose-variant.jpg'],
        );

        $this->artisan('shopify:debug-product-options', ['handle' => 'verbose-image-handle', '--verbose' => true])
            ->expectsOutputToContain('image_url: yes')
            ->expectsOutputToContain('image_url_value: https://cdn.example.com/verbose-variant.jpg')
            ->assertSuccessful();
    }

    public function test_command_lists_first_products_when_handle_is_not_provided(): void
    {
        VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'listed-product']);

        $this->artisan('shopify:debug-product-options')
            ->expectsOutputToContain('listed-product')
            ->assertSuccessful();
    }

    public function test_command_fails_for_unknown_handle(): void
    {
        $this->artisan('shopify:debug-product-options', ['handle' => 'missing-handle'])
            ->assertFailed();
    }

    public function test_command_warns_when_shopify_reports_more_variants_than_local_db(): void
    {
        VarleCatalogFixtures::createExportableVariant(productOverrides: [
            'handle' => 'truncated-product',
            'raw_payload' => [
                'totalVariants' => 10,
                'options' => [
                    ['name' => 'Spalva', 'values' => ['Juoda']],
                ],
            ],
        ]);

        $this->artisan('shopify:debug-product-options', ['handle' => 'truncated-product'])
            ->expectsOutputToContain('Local variant count: 1')
            ->expectsOutputToContain('Shopify reports 10 variants but local DB has 1.')
            ->assertSuccessful();
    }
}
