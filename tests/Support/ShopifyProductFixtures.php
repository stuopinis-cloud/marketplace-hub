<?php

namespace Tests\Support;

class ShopifyProductFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function product(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'gid://shopify/Product/1001',
            'title' => 'Test Product',
            'descriptionHtml' => '<p>Description</p>',
            'vendor' => 'Test Vendor',
            'productType' => 'Shoes',
            'handle' => 'test-product',
            'status' => 'ACTIVE',
            'options' => [
                ['name' => 'Size', 'values' => ['M', 'L']],
                ['name' => 'Color', 'values' => ['Black', 'White']],
            ],
            'media' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/MediaImage/1',
                        'alt' => 'Front',
                        'image' => [
                            'url' => 'https://cdn.shopify.com/image-1.jpg',
                            'altText' => 'Front',
                        ],
                    ],
                ],
            ],
            'variants' => [
                'nodes' => [
                    self::variant(),
                ],
            ],
            'tags' => ['tactical', 'vest'],
            'collections' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/Collection/3001',
                        'title' => 'Tactical Vests',
                        'handle' => 'tactical-vests',
                    ],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function variant(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'gid://shopify/ProductVariant/2001',
            'title' => 'Default',
            'sku' => 'SKU-2001',
            'barcode' => '1234567890',
            'price' => '19.99',
            'compareAtPrice' => '24.99',
            'selectedOptions' => [
                ['name' => 'Size', 'value' => 'M'],
                ['name' => 'Color', 'value' => 'Black'],
            ],
            'inventoryItem' => [
                'measurement' => [
                    'weight' => [
                        'value' => 1.25,
                        'unit' => 'KILOGRAMS',
                    ],
                ],
                'inventoryLevels' => [
                    'nodes' => [
                        [
                            'quantities' => [
                                ['name' => 'available', 'quantity' => 5],
                            ],
                        ],
                        [
                            'quantities' => [
                                ['name' => 'available', 'quantity' => 3],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public static function productsResponse(
        array $products,
        bool $hasNextPage = false,
        ?string $endCursor = null,
    ): array {
        return [
            'data' => [
                'products' => [
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                    'nodes' => $products,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    public static function productVariantsResponse(
        array $variants,
        bool $hasNextPage = false,
        ?string $endCursor = null,
    ): array {
        return [
            'data' => [
                'product' => [
                    'variants' => [
                        'pageInfo' => [
                            'hasNextPage' => $hasNextPage,
                            'endCursor' => $endCursor,
                        ],
                        'nodes' => $variants,
                    ],
                ],
            ],
        ];
    }
}
