<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleVariantPresenterImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_export_image_urls_puts_variant_images_first(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();
        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/generic-gallery.jpg',
            'position' => 2,
            'alt' => 'Generic gallery',
        ]);
        $product->refresh()->load(['images', 'variants']);

        $melyniVariants = $this->validVariantRows($product, fn ($variant): bool => $variant->option1_value === 'Mėlyni');

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product, $melyniVariants, [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertSame([
            'https://cdn.example.com/melyni.jpg',
            'https://cdn.example.com/multi.jpg',
            'https://cdn.example.com/generic-gallery.jpg',
        ], $resolution['urls']);
        $this->assertFalse($resolution['used_fallback']);
        $this->assertSame(1, $resolution['variant_images_count']);
        $this->assertSame(2, $resolution['generic_gallery_images_count']);
        $this->assertSame(1, $resolution['forbidden_variant_images_count']);
    }

    public function test_color_split_excludes_other_color_variant_images(): void
    {
        $product = VarleCatalogFixtures::createColorSizeProduct();

        $melyniVariants = $this->validVariantRows($product, fn ($variant): bool => $variant->option1_value === 'Mėlyni');
        $juodiVariants = $this->validVariantRows($product, fn ($variant): bool => $variant->option1_value === 'Juodi');

        $melyniResolution = VarleVariantPresenter::resolveExportImageUrls($product, $melyniVariants, [
            'allow_fallback_product_images' => false,
        ]);
        $juodiResolution = VarleVariantPresenter::resolveExportImageUrls($product, $juodiVariants, [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertNotContains('https://cdn.example.com/juodi.jpg', $melyniResolution['urls']);
        $this->assertContains('https://cdn.example.com/melyni.jpg', $melyniResolution['urls']);
        $this->assertNotContains('https://cdn.example.com/melyni.jpg', $juodiResolution['urls']);
        $this->assertContains('https://cdn.example.com/juodi.jpg', $juodiResolution['urls']);
    }

    public function test_duplicate_image_urls_are_deduped_with_variant_image_first(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(
            variantOverrides: ['image_url' => 'https://cdn.example.com/shared.jpg'],
        );
        $product = Product::query()->with(['images', 'variants'])->findOrFail($variant->product_id);
        $product->images()->update(['url' => 'https://cdn.example.com/shared.jpg']);
        $product->refresh()->load(['images', 'variants']);

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product, [[
            'variant' => $variant,
            'quantity' => 1,
        ]], [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertSame(['https://cdn.example.com/shared.jpg'], $resolution['urls']);
        $this->assertSame(1, $resolution['variant_images_count']);
        $this->assertSame(0, $resolution['generic_gallery_images_count']);
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
        $this->assertSame(0, $resolution['variant_images_count']);
        $this->assertSame(1, $resolution['generic_gallery_images_count']);
    }

    public function test_resolve_export_image_urls_returns_empty_when_no_variant_image_and_fallback_disabled(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(
            variantOverrides: ['image_url' => null],
        );
        $product = Product::query()->with(['images', 'variants'])->findOrFail($variant->product_id);

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product, [[
            'variant' => $variant,
            'quantity' => 1,
        ]], [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertSame([], $resolution['urls']);
        $this->assertFalse($resolution['used_fallback']);
    }

    public function test_non_color_product_includes_variant_images_then_product_images(): void
    {
        $product = VarleCatalogFixtures::createMultiVariantProduct(
            productOverrides: ['handle' => 'size-only-images'],
            variantDefinitions: [
                [
                    'sku' => 'SKU-S',
                    'barcode' => '4770000000601',
                    'price' => 20,
                    'option1' => 'S',
                    'option1_name' => 'Dydis',
                    'option1_value' => 'S',
                    'image_url' => 'https://cdn.example.com/variant-s.jpg',
                ],
            ],
        );

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/detail.jpg',
            'position' => 2,
        ]);

        $validVariants = $product->variants->map(fn ($variant) => [
            'variant' => $variant,
            'quantity' => 1,
        ])->all();

        $resolution = VarleVariantPresenter::resolveExportImageUrls($product->fresh(['images', 'variants']), $validVariants, [
            'allow_fallback_product_images' => false,
        ]);

        $this->assertSame([
            'https://cdn.example.com/variant-s.jpg',
            'https://cdn.example.com/multi.jpg',
            'https://cdn.example.com/detail.jpg',
        ], $resolution['urls']);
    }

    /**
     * @param  callable(\App\Models\ProductVariant): bool  $filter
     * @return array<int, array{variant: \App\Models\ProductVariant, quantity: int}>
     */
    private function validVariantRows(Product $product, callable $filter): array
    {
        return $product->variants
            ->filter($filter)
            ->map(fn ($variant) => [
                'variant' => $variant,
                'quantity' => 1,
            ])
            ->values()
            ->all();
    }
}
