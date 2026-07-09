<?php

namespace App\Services\Marketplace;

use App\Models\ProductVariant;
use App\Models\SupplierProduct;

class ProductAvailabilityResolver
{
    /**
     * @return array{
     *     available: bool,
     *     source: 'shopify'|'supplier'|'backorder'|null,
     *     quantity: int,
     *     delivery_days_min: int|null,
     *     delivery_days_max: int|null,
     * }
     */
    public function resolve(ProductVariant $variant): array
    {
        $variant->loadMissing('inventoryLevels', 'supplierProducts.supplier');

        $shopifyQuantity = (int) $variant->inventoryLevels->sum('quantity');

        if ($shopifyQuantity > 0) {
            return [
                'available' => true,
                'source' => 'shopify',
                'quantity' => $shopifyQuantity,
                'delivery_days_min' => 1,
                'delivery_days_max' => 2,
            ];
        }

        $supplierQuantity = (int) $variant->supplierProducts
            ->filter(fn (SupplierProduct $supplierProduct): bool =>
                $supplierProduct->enabled
                && (bool) optional($supplierProduct->supplier)->enabled
                && $supplierProduct->stock_quantity > 0
            )
            ->sum('stock_quantity');

        if ($supplierQuantity > 0) {
            return [
                'available' => true,
                'source' => 'supplier',
                'quantity' => $supplierQuantity,
                'delivery_days_min' => 5,
                'delivery_days_max' => 10,
            ];
        }

        if ((bool) $variant->backorder_allowed) {
            return [
                'available' => true,
                'source' => 'backorder',
                'quantity' => 0,
                'delivery_days_min' => null,
                'delivery_days_max' => null,
            ];
        }

        return [
            'available' => false,
            'source' => null,
            'quantity' => 0,
            'delivery_days_min' => null,
            'delivery_days_max' => null,
        ];
    }
}
