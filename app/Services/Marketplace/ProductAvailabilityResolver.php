<?php

namespace App\Services\Marketplace;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Varle\VarleStockEvaluator;

class ProductAvailabilityResolver
{
    public const string SOURCE_SHOPIFY = 'shopify';

    public const string SOURCE_SUPPLIER = 'supplier';

    public const string SOURCE_BACKORDER = 'backorder';

    /**
     * @param  array{
     *     in_stock_delivery_text?: string,
     *     backorder_delivery_text?: string,
     *     allow_backorder_export?: bool
     * }|null  $deliveryRule
     * @return array{
     *     exportable: bool,
     *     available: bool,
     *     source_type: 'shopify'|'supplier'|'backorder'|null,
     *     source: 'shopify'|'supplier'|'backorder'|null,
     *     supplier_id: ?int,
     *     quantity: int,
     *     stock_status: string,
     *     delivery_text: ?string,
     *     delivery_class: string,
     *     reason: ?string,
     *     issue_code: ?string,
     *     issue_message: ?string,
     *     local_quantity: int,
     *     supplier_quantity: int,
     *     supplier_name: ?string,
     *     is_stale: bool,
     *     supplier_match_status: ?string,
     *     delivery_days_min: ?int,
     *     delivery_days_max: ?int
     * }
     */
    public function resolve(ProductVariant $variant, ?array $deliveryRule = null): array
    {
        $variant->loadMissing('inventoryLevels', 'supplierProducts.supplier', 'product');

        $localQuantity = (int) $variant->inventoryLevels->sum('quantity');
        $supplierSelection = $this->selectSupplierStock($variant);
        $supplierQuantity = $supplierSelection['quantity'];
        $isStale = $supplierSelection['is_stale'];

        if ($localQuantity > 0) {
            return $this->buildResult(
                exportable: true,
                sourceType: self::SOURCE_SHOPIFY,
                quantity: $localQuantity,
                stockStatus: 'in_stock',
                deliveryClass: VarleStockEvaluator::CLASS_IN_STOCK,
                deliveryText: $deliveryRule['in_stock_delivery_text'] ?? null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierQuantity,
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($supplierQuantity > 0 && ! $isStale) {
            return $this->buildResult(
                exportable: true,
                sourceType: self::SOURCE_SUPPLIER,
                quantity: $supplierQuantity,
                stockStatus: 'supplier_stock',
                deliveryClass: VarleStockEvaluator::CLASS_SUPPLIER,
                deliveryText: $supplierSelection['delivery_text'],
                supplierId: $supplierSelection['supplier_id'],
                localQuantity: $localQuantity,
                supplierQuantity: $supplierQuantity,
                supplierName: $supplierSelection['supplier_name'],
                isStale: false,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($isStale && $supplierSelection['raw_supplier_quantity'] > 0) {
            $staleResult = $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'stale_supplier',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierSelection['raw_supplier_quantity'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: true,
                supplierMatchStatus: $supplierSelection['match_status'],
                reason: 'Supplier stock is stale.',
                issueCode: 'supplier_stock_stale',
                issueMessage: 'Supplier stock is stale.',
            );

            if ($this->canExportBackorder($variant, $deliveryRule)) {
                return $this->backorderResult($localQuantity, $supplierSelection, $deliveryRule);
            }

            return $staleResult;
        }

        if ($this->canExportBackorder($variant, $deliveryRule)) {
            return $this->backorderResult($localQuantity, $supplierSelection, $deliveryRule);
        }

        if ($variant->backorder_allowed && ! ($deliveryRule['allow_backorder_export'] ?? true)) {
            return $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'unavailable',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierQuantity,
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
                reason: 'Backorder export is disabled for this vendor.',
                issueCode: 'backorder_disabled_for_vendor',
                issueMessage: 'Backorder export is disabled for this vendor.',
            );
        }

        return $this->buildResult(
            exportable: false,
            sourceType: null,
            quantity: 0,
            stockStatus: 'unavailable',
            deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
            deliveryText: null,
            localQuantity: $localQuantity,
            supplierQuantity: $supplierQuantity,
            supplierName: $supplierSelection['supplier_name'],
            isStale: $isStale,
            supplierMatchStatus: $supplierSelection['match_status'],
            reason: 'No stock available from Shopify or supplier.',
            issueCode: 'out_of_stock_no_backorder',
            issueMessage: 'Out of stock and backorder is not allowed.',
        );
    }

    /**
     * @return array{
     *     quantity: int,
     *     raw_supplier_quantity: int,
     *     supplier_id: ?int,
     *     supplier_name: ?string,
     *     delivery_text: ?string,
     *     match_status: ?string,
     *     is_stale: bool
     * }
     */
    private function selectSupplierStock(ProductVariant $variant): array
    {
        $candidates = $variant->supplierProducts
            ->filter(function (SupplierProduct $supplierProduct): bool {
                $supplier = $supplierProduct->supplier;

                return $supplierProduct->enabled
                    && $supplier instanceof Supplier
                    && $supplier->enabled
                    && $supplierProduct->product_variant_id !== null
                    && $supplierProduct->match_status === SupplierProduct::MATCH_STATUS_MATCHED
                    && $supplierProduct->stock_quantity > 0
                    && $supplierProduct->availability_status !== SupplierProduct::AVAILABILITY_UNAVAILABLE;
            })
            ->sortBy(fn (SupplierProduct $supplierProduct): int => (int) optional($supplierProduct->supplier)->stock_priority)
            ->values();

        $rawSupplierQuantity = (int) $candidates->sum('stock_quantity');

        $usable = $candidates->first(function (SupplierProduct $supplierProduct): bool {
            return ! $supplierProduct->isStale();
        });

        if (! $usable instanceof SupplierProduct) {
            $first = $candidates->first();

            return [
                'quantity' => 0,
                'raw_supplier_quantity' => $rawSupplierQuantity,
                'supplier_id' => $first?->supplier_id,
                'supplier_name' => $first?->supplier?->name,
                'delivery_text' => $first?->supplier?->in_stock_delivery_text,
                'match_status' => $first?->match_status,
                'is_stale' => $rawSupplierQuantity > 0,
            ];
        }

        return [
            'quantity' => (int) $usable->stock_quantity,
            'raw_supplier_quantity' => $rawSupplierQuantity,
            'supplier_id' => $usable->supplier_id,
            'supplier_name' => $usable->supplier?->name,
            'delivery_text' => $usable->supplier?->in_stock_delivery_text,
            'match_status' => $usable->match_status,
            'is_stale' => false,
        ];
    }

    /**
     * @param  array{
     *     quantity: int,
     *     raw_supplier_quantity: int,
     *     supplier_id: ?int,
     *     supplier_name: ?string,
     *     delivery_text: ?string,
     *     match_status: ?string,
     *     is_stale: bool
     * }  $supplierSelection
     * @param  array<string, mixed>|null  $deliveryRule
     * @return array<string, mixed>
     */
    private function backorderResult(int $localQuantity, array $supplierSelection, ?array $deliveryRule): array
    {
        return $this->buildResult(
            exportable: true,
            sourceType: self::SOURCE_BACKORDER,
            quantity: 0,
            stockStatus: 'backorder',
            deliveryClass: VarleStockEvaluator::CLASS_BACKORDER,
            deliveryText: $deliveryRule['backorder_delivery_text'] ?? null,
            localQuantity: $localQuantity,
            supplierQuantity: $supplierSelection['quantity'],
            supplierName: $supplierSelection['supplier_name'],
            isStale: $supplierSelection['is_stale'],
            supplierMatchStatus: $supplierSelection['match_status'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $deliveryRule
     */
    private function canExportBackorder(ProductVariant $variant, ?array $deliveryRule): bool
    {
        return (bool) $variant->backorder_allowed
            && ($deliveryRule['allow_backorder_export'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResult(
        bool $exportable,
        ?string $sourceType,
        int $quantity,
        string $stockStatus,
        string $deliveryClass,
        ?string $deliveryText,
        int $localQuantity,
        int $supplierQuantity,
        ?string $supplierName,
        bool $isStale,
        ?string $supplierMatchStatus,
        ?int $supplierId = null,
        ?string $reason = null,
        ?string $issueCode = null,
        ?string $issueMessage = null,
    ): array {
        return [
            'exportable' => $exportable,
            'available' => $exportable,
            'source_type' => $sourceType,
            'source' => $sourceType,
            'supplier_id' => $supplierId,
            'quantity' => $quantity,
            'stock_status' => $stockStatus,
            'delivery_text' => $deliveryText,
            'delivery_class' => $deliveryClass,
            'reason' => $reason,
            'issue_code' => $issueCode,
            'issue_message' => $issueMessage,
            'local_quantity' => $localQuantity,
            'supplier_quantity' => $supplierQuantity,
            'supplier_name' => $supplierName,
            'is_stale' => $isStale,
            'supplier_match_status' => $supplierMatchStatus,
            'delivery_days_min' => match ($sourceType) {
                self::SOURCE_SHOPIFY => 1,
                self::SOURCE_SUPPLIER => 5,
                default => null,
            },
            'delivery_days_max' => match ($sourceType) {
                self::SOURCE_SHOPIFY => 2,
                self::SOURCE_SUPPLIER => 10,
                default => null,
            },
        ];
    }
}
