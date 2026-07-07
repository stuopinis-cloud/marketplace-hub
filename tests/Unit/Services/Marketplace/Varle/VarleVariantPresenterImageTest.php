<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\Product;
use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleVariantPresenterImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_export_image_urls_uses_variant_images_in_variant_order(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();

        $variants = $product->variants->sortBy('id')->values();
        $validVariants = $variants->map(fn ($variant) => [
            'variant' => $variant,
            'quantity' => 1,
        ])->all();

        $melyniVariants = array_values(array_filter(
            $validVariants,
            fn (array $row): bool => $row['variant']->option1_value === 'Mėlyni',
        ));

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product, $melyniVariants, [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertSame(['https://cdn.example.com/melyni.jpg'], $resolution['urls']);
        $this->assertFalse($resolution['used_fallback']);
    }

    public function test_resolve_export_image_urls_falls_back_to_product_images_when_enabled(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(
            variantOverrides: ['image_url' => null],
        );
        $product = Product::query()->with('images')->findOrFail($variant->product_id);

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product, [[
            'variant' => $variant,
            'quantity' => 1,
        ]], [
            'allow_fallback_product_images' => true,
        ]);

        $this->assertSame(['https://cdn.example.com/image.jpg'], $resolution['urls']);
        $this->assertTrue($resolution['used_fallback']);
    }
}
