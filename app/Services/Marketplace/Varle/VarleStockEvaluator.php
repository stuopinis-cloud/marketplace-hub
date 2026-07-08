<?php

namespace App\Services\Marketplace\Varle;

use App\Models\ProductVariant;

class VarleStockEvaluator
{
    public const string CLASS_IN_STOCK = 'in_stock';

    public const string CLASS_BACKORDER = 'backorder';

    public const string CLASS_BLOCKED = 'blocked';

    /**
     * @param  array{
     *     allow_backorder_export: bool
     * }  $deliveryRule
     * @return array{
     *     exportable: bool,
     *     delivery_class: string,
     *     issue_code: ?string,
     *     issue_message: ?string,
     *     quantity: int
     * }
     */
    public function assessVariant(ProductVariant $variant, int $quantity, array $deliveryRule): array
    {
        if ($quantity > 0) {
            return [
                'exportable' => true,
                'delivery_class' => self::CLASS_IN_STOCK,
                'issue_code' => null,
                'issue_message' => null,
                'quantity' => $quantity,
            ];
        }

        if ($variant->backorder_allowed && ($deliveryRule['allow_backorder_export'] ?? true)) {
            return [
                'exportable' => true,
                'delivery_class' => self::CLASS_BACKORDER,
                'issue_code' => null,
                'issue_message' => null,
                'quantity' => $quantity,
            ];
        }

        if ($variant->backorder_allowed && ! ($deliveryRule['allow_backorder_export'] ?? true)) {
            return [
                'exportable' => false,
                'delivery_class' => self::CLASS_BLOCKED,
                'issue_code' => 'backorder_disabled_for_vendor',
                'issue_message' => 'Backorder export is disabled for this vendor.',
                'quantity' => $quantity,
            ];
        }

        return [
            'exportable' => false,
            'delivery_class' => self::CLASS_BLOCKED,
            'issue_code' => 'out_of_stock_no_backorder',
            'issue_message' => 'Out of stock and backorder is not allowed.',
            'quantity' => $quantity,
        ];
    }

    public static function inventoryPolicyAllowsBackorder(?string $inventoryPolicy): bool
    {
        return strtoupper((string) $inventoryPolicy) === 'CONTINUE';
    }
}
