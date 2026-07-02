<?php

namespace Tests\Support;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;

class VarleCatalogFixtures
{
    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<string, mixed>  $variantOverrides
     */
    public static function createExportableVariant(
        array $productOverrides = [],
        array $variantOverrides = [],
    ): ProductVariant {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        $product = Product::query()->create(array_merge([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Exportable Product',
            'description_html' => '<p>Product description</p>',
            'vendor' => 'Vendor Name',
            'brand' => 'Brand Name',
            'product_type' => 'Shoes',
            'category' => 'Footwear',
            'status' => ProductStatus::Active,
            'varle_export_status' => VarleExportStatus::Auto,
            'handle' => 'exportable-product',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva'],
                    ['name' => 'Dydis'],
                ],
            ],
            'imported_at' => now(),
        ], $productOverrides));

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/image.jpg',
            'position' => 0,
            'alt' => 'Main image',
        ]);

        $variant = ProductVariant::query()->create(array_merge([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'barcode' => '5901234123457',
            'title' => 'Default',
            'price' => 19.99,
            'option1' => 'RAL7013',
            'option1_name' => 'Spalva',
            'option1_value' => 'RAL7013',
            'option2' => 'S',
            'option2_name' => 'Dydis',
            'option2_value' => 'S',
            'raw_payload' => [
                'selectedOptions' => [
                    ['name' => 'Spalva', 'value' => 'RAL7013'],
                    ['name' => 'Dydis', 'value' => 'S'],
                ],
            ],
        ], self::normalizeVariantOptionFields($variantOverrides)));

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 5,
        ]);

        return $variant->fresh(['product.images', 'inventoryLevels', 'product']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $variantDefinitions
     */
    public static function createMultiVariantProduct(
        array $productOverrides = [],
        array $variantDefinitions = [],
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        $product = Product::query()->create(array_merge([
            'source_id' => $source->id,
            'external_id' => 'product-multi-'.uniqid(),
            'title' => 'Multi Variant Product',
            'description_html' => '<p>Multi variant description</p>',
            'vendor' => 'Vendor Name',
            'brand' => 'Brand Name',
            'product_type' => 'Apparel',
            'category' => 'Clothing',
            'status' => ProductStatus::Active,
            'varle_export_status' => VarleExportStatus::Auto,
            'handle' => 'multi-variant-product',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva'],
                    ['name' => 'Dydis'],
                ],
            ],
            'imported_at' => now(),
        ], $productOverrides));

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/multi.jpg',
            'position' => 0,
            'alt' => 'Main image',
        ]);

        $definitions = $variantDefinitions !== [] ? $variantDefinitions : [
            [
                'sku' => 'SKU-S',
                'barcode' => '4770000000001',
                'title' => 'Small',
                'price' => 20,
                'option1' => 'RAL7013',
                'option2' => 'S',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'RAL7013'],
                        ['name' => 'Dydis', 'value' => 'S'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-L',
                'barcode' => '4770000000002',
                'title' => 'Large',
                'price' => 25,
                'option1' => 'RAL7013',
                'option2' => 'L',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'RAL7013'],
                        ['name' => 'Dydis', 'value' => 'L'],
                    ],
                ],
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $quantity = $definition['quantity'] ?? ($index + 1);
            unset($definition['quantity']);

            $definition = self::normalizeVariantOptionFields($definition);

            $variant = ProductVariant::query()->create(array_merge([
                'product_id' => $product->id,
                'external_id' => 'variant-'.$index,
            ], $definition));

            InventoryLevel::query()->create([
                'variant_id' => $variant->id,
                'warehouse_name' => 'Shopify',
                'quantity' => $quantity,
            ]);
        }

        return $product->fresh(['images', 'variants.inventoryLevels']);
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<int, array<string, mixed>>  $variantDefinitions
     */
    public static function createColorSizeProduct(
        array $productOverrides = [],
        array $variantDefinitions = [],
    ): Product {
        $definitions = $variantDefinitions !== [] ? $variantDefinitions : [
            [
                'sku' => 'SKU-MELYNI-M',
                'barcode' => '4770000000001',
                'price' => 20,
                'option1' => 'Mėlyni',
                'option2' => 'M',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Mėlyni'],
                        ['name' => 'Dydis', 'value' => 'M'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-MELYNI-L',
                'barcode' => '4770000000002',
                'price' => 25,
                'option1' => 'Mėlyni',
                'option2' => 'L',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Mėlyni'],
                        ['name' => 'Dydis', 'value' => 'L'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-JUODI-M',
                'barcode' => '4770000000003',
                'price' => 22,
                'option1' => 'Juodi',
                'option2' => 'M',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Juodi'],
                        ['name' => 'Dydis', 'value' => 'M'],
                    ],
                ],
            ],
        ];

        return self::createMultiVariantProduct(array_merge([
            'title' => 'Vyriški marškiniai K459',
            'handle' => 'vyriski-marskiniai-k459',
            'vendor' => 'Shopify Vendor',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva'],
                    ['name' => 'Dydis'],
                ],
            ],
        ], $productOverrides), $definitions);
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<int, array<string, mixed>>  $variantDefinitions
     */
    public static function createColorOnlyProduct(
        array $productOverrides = [],
        array $variantDefinitions = [],
    ): Product {
        $definitions = $variantDefinitions !== [] ? $variantDefinitions : [
            [
                'sku' => 'SKU-JUODA',
                'barcode' => '4770000000101',
                'price' => 20,
                'option1' => 'Juoda',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Juoda'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-ZALIA',
                'barcode' => '4770000000102',
                'price' => 22,
                'option1' => 'Žalia',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Žalia'],
                    ],
                ],
            ],
        ];

        return self::createMultiVariantProduct(array_merge([
            'title' => 'Product title',
            'handle' => 'color-only-product',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva'],
                ],
            ],
        ], $productOverrides), $definitions);
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<int, array<string, mixed>>  $variantDefinitions
     */
    public static function createSizeOnlyProduct(
        array $productOverrides = [],
        array $variantDefinitions = [],
    ): Product {
        $definitions = $variantDefinitions !== [] ? $variantDefinitions : [
            [
                'sku' => 'SKU-S',
                'barcode' => '4770000000201',
                'price' => 20,
                'option1' => 'S',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Dydis', 'value' => 'S'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-M',
                'barcode' => '4770000000202',
                'price' => 25,
                'option1' => 'M',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Dydis', 'value' => 'M'],
                    ],
                ],
            ],
        ];

        return self::createMultiVariantProduct(array_merge([
            'title' => 'Size Only Product',
            'handle' => 'size-only-product',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Dydis'],
                ],
            ],
        ], $productOverrides), $definitions);
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<int, array<string, mixed>>  $variantDefinitions
     */
    public static function createMultiNonColorOptionProduct(
        array $productOverrides = [],
        array $variantDefinitions = [],
    ): Product {
        $definitions = $variantDefinitions !== [] ? $variantDefinitions : [
            [
                'sku' => 'SKU-M-KAIRE',
                'barcode' => '4770000000301',
                'price' => 30,
                'option1' => 'M',
                'option2' => 'Kairė',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Dydis', 'value' => 'M'],
                        ['name' => 'Pusė', 'value' => 'Kairė'],
                    ],
                ],
            ],
        ];

        return self::createMultiVariantProduct(array_merge([
            'title' => 'Gloves Product',
            'handle' => 'gloves-product',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Dydis'],
                    ['name' => 'Pusė'],
                ],
            ],
        ], $productOverrides), $definitions);
    }

    public static function createMechanixGlovesProduct(): Product
    {
        return self::createMultiVariantProduct([
            'title' => 'Taktinės žieminės pirštinės Mechanix ColdWork FastFit',
            'handle' => 'taktines-ziemines-pirstines-mechanix-coldwork-fastfit',
            'raw_payload' => [],
        ], [
            [
                'sku' => 'CWKTFF-72-008',
                'barcode' => '4770000000401',
                'price' => 30,
                'option1' => 'Coyote',
                'option1_name' => 'Spalva',
                'option1_value' => 'Coyote',
                'option2' => 'S',
                'option2_name' => 'Dydis',
                'option2_value' => 'S',
            ],
            [
                'sku' => 'CWKTFF-72-009',
                'barcode' => '4770000000402',
                'price' => 30,
                'option1' => 'Coyote',
                'option1_name' => 'Spalva',
                'option1_value' => 'Coyote',
                'option2' => 'M',
                'option2_name' => 'Dydis',
                'option2_value' => 'M',
            ],
            [
                'sku' => 'CWKTFF-55-008',
                'barcode' => '4770000000403',
                'price' => 32,
                'option1' => 'Juoda',
                'option1_name' => 'Spalva',
                'option1_value' => 'Juoda',
                'option2' => 'S',
                'option2_name' => 'Dydis',
                'option2_value' => 'S',
            ],
            [
                'sku' => 'CWKTFF-55-009',
                'barcode' => '4770000000404',
                'price' => 32,
                'option1' => 'Juoda',
                'option1_name' => 'Spalva',
                'option1_value' => 'Juoda',
                'option2' => 'M',
                'option2_name' => 'Dydis',
                'option2_value' => 'M',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function normalizeVariantOptionFields(array $definition): array
    {
        $selectedOptions = data_get($definition, 'raw_payload.selectedOptions', []);

        if (! is_array($selectedOptions)) {
            return $definition;
        }

        foreach ($selectedOptions as $index => $option) {
            if (! is_array($option)) {
                continue;
            }

            $slot = $index + 1;

            if ($slot > 3) {
                break;
            }

            $definition["option{$slot}_name"] ??= $option['name'] ?? null;
            $definition["option{$slot}_value"] ??= $option['value'] ?? null;
            $definition["option{$slot}"] ??= $option['value'] ?? null;
        }

        return $definition;
    }
}
