<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleVariantPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_color_option_name_matches_expected_values(): void
    {
        $this->assertTrue(VarleVariantPresenter::isColorOptionName('Spalva'));
        $this->assertTrue(VarleVariantPresenter::isColorOptionName('Colour'));
        $this->assertFalse(VarleVariantPresenter::isColorOptionName('Dydis'));
    }

    public function test_is_size_option_name_matches_expected_values(): void
    {
        $this->assertTrue(VarleVariantPresenter::isSizeOptionName('Dydis'));
        $this->assertTrue(VarleVariantPresenter::isSizeOptionName('dydziai'));
        $this->assertFalse(VarleVariantPresenter::isSizeOptionName('Spalva'));
    }

    public function test_non_color_group_title_uses_non_color_option_names(): void
    {
        $product = VarleCatalogFixtures::createMultiVariantProduct();

        $this->assertSame('Dydis', VarleVariantPresenter::nonColorGroupTitle($product));
    }

    public function test_variant_display_title_uses_only_non_color_values(): void
    {
        $product = VarleCatalogFixtures::createMultiVariantProduct();
        $variant = $product->variants->first();

        $this->assertSame('S', VarleVariantPresenter::variantDisplayTitle($product, $variant));
    }

    public function test_color_value_and_slug_are_detected(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();
        $variant = $product->variants->first();

        $this->assertSame('Mėlyni', VarleVariantPresenter::colorValue($product, $variant));
        $this->assertSame('melyni', VarleVariantPresenter::colorSlug('Mėlyni'));
    }

    public function test_should_output_variants_when_single_variant_has_non_color_options(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();
        $variant = $product->variants->last();

        $this->assertTrue(VarleVariantPresenter::shouldOutputVariants($product, [[
            'variant' => $variant,
            'quantity' => 1,
        ]]));
    }

    public function test_should_not_output_variants_for_color_only_single_variant(): void
    {
        $product = VarleCatalogFixtures::createColorOnlyProduct();
        $variant = $product->variants->first();

        $this->assertFalse(VarleVariantPresenter::shouldOutputVariants($product, [[
            'variant' => $variant,
            'quantity' => 1,
        ]]));
    }

    public function test_should_not_output_variants_for_default_title_option(): void
    {
        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct();
        $product = $variant->product;

        $this->assertFalse(VarleVariantPresenter::shouldOutputVariants($product, [[
            'variant' => $variant,
            'quantity' => 5,
        ]]));
        $this->assertTrue(VarleVariantPresenter::isSimpleShopifyProduct($product));
        $this->assertSame([], VarleVariantPresenter::detectMeaningfulOptions($product));
    }

    public function test_is_meaningful_option_rejects_default_title_values(): void
    {
        $this->assertFalse(VarleVariantPresenter::isMeaningfulOption([
            'name' => 'Title',
            'value' => 'Default Title',
        ]));
        $this->assertTrue(VarleVariantPresenter::isMeaningfulOption([
            'name' => 'Dydis',
            'value' => 'M',
        ]));
    }

    public function test_get_non_color_options_excludes_color_option(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();
        $variant = $product->variants->first();

        $options = VarleVariantPresenter::getNonColorOptions($product, $variant);

        $this->assertCount(1, $options);
        $this->assertSame('Dydis', $options[0]['name']);
        $this->assertSame('M', $options[0]['value']);
    }

    public function test_detects_color_from_variant_option_name_column_without_product_options(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct([
            'raw_payload' => [],
        ]);
        $variant = $product->variants->first();

        $this->assertTrue(VarleVariantPresenter::productHasColorOption($product));
        $this->assertTrue(VarleVariantPresenter::productHasSizeOption($product));
        $this->assertSame('Mėlyni', VarleVariantPresenter::colorValue($product, $variant));
        $this->assertSame('Dydis', VarleVariantPresenter::nonColorGroupTitle($product));
    }
}
