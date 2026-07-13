<?php

namespace App\Services\Marketplace;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Varle\VarleStockEvaluator;
use App\Services\Suppliers\SupplierAvailabilityEvaluator;

class ProductAvailabilityResolver
{
    public const string SOURCE_SHOPIFY = 'shopify';

    public const string SOURCE_SUPPLIER = 'supplier';

    public const string SOURCE_SUPPLIER_AVAILABILITY_FALLBACK = 'supplier_availability_fallback';

    public const string SOURCE_BACKORDER = 'backorder';

    public const int DEFAULT_AVAILABILITY_FALLBACK_QUANTITY = 5;

    public function __construct(
        private readonly SupplierAvailabilityEvaluator $availabilityEvaluator,
    ) {}

    /**
     * @param  array{
     *     in_stock_delivery_text?: string,
     *     backorder_delivery_text?: string,
     *     allow_backorder_export?: bool
     * }|null  $deliveryRule
     * @return array{
     *     exportable: bool,
     *     available: bool,
     *     source_type: 'shopify'|'supplier'|'supplier_availability_fallback'|'backorder'|null,
     *     source: 'shopify'|'supplier'|'supplier_availability_fallback'|'backorder'|null,
     *     supplier_id: ?int,
     *     quantity: int,
     *     stock_status: string,
     *     delivery_text: ?string,
     *     delivery_class: string,
     *     reason: ?string,
     *     issue_code: ?string,
     *     issue_message: ?string,
     *     local_quantity: int,
     *     supplier_quantity: ?int,
     *     supplier_availability: ?string,
     *     used_availability_fallback: bool,
     *     availability_fallback_quantity: ?int,
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
                supplierQuantity: $supplierSelection['numeric_quantity'],
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($supplierSelection['source_type'] === self::SOURCE_SUPPLIER) {
            return $this->buildResult(
                exportable: true,
                sourceType: self::SOURCE_SUPPLIER,
                quantity: (int) $supplierQuantity,
                stockStatus: 'supplier_stock',
                deliveryClass: VarleStockEvaluator::CLASS_SUPPLIER,
                deliveryText: $supplierSelection['delivery_text'],
                supplierId: $supplierSelection['supplier_id'],
                localQuantity: $localQuantity,
                supplierQuantity: (int) $supplierQuantity,
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: false,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($supplierSelection['source_type'] === self::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK) {
            return $this->buildResult(
                exportable: true,
                sourceType: self::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK,
                quantity: (int) $supplierQuantity,
                stockStatus: 'supplier_availability_fallback',
                deliveryClass: VarleStockEvaluator::CLASS_SUPPLIER,
                deliveryText: $supplierSelection['delivery_text'],
                supplierId: $supplierSelection['supplier_id'],
                localQuantity: $localQuantity,
                supplierQuantity: null,
                supplierAvailability: $supplierSelection['availability_status'],
                usedAvailabilityFallback: true,
                availabilityFallbackQuantity: (int) $supplierQuantity,
                supplierName: $supplierSelection['supplier_name'],
                isStale: false,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($this->variantAllowsBackorder($variant) && ($deliveryRule['allow_backorder_export'] ?? true)) {
            return $this->buildResult(
                exportable: true,
                sourceType: self::SOURCE_BACKORDER,
                quantity: 1,
                stockStatus: 'backorder',
                deliveryClass: VarleStockEvaluator::CLASS_BACKORDER,
                deliveryText: $deliveryRule['backorder_delivery_text'] ?? null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierSelection['numeric_quantity'],
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
            );
        }

        if ($this->variantAllowsBackorder($variant) && ! ($deliveryRule['allow_backorder_export'] ?? true)) {
            return $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'unavailable',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierSelection['numeric_quantity'],
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
                reason: 'Backorder export is disabled for this vendor.',
                issueCode: 'backorder_disabled_for_vendor',
                issueMessage: 'Backorder export is disabled for this vendor.',
            );
        }

        if ($isStale && $supplierSelection['raw_supplier_quantity'] > 0) {
            return $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'stale_supplier',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierSelection['raw_supplier_quantity'],
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: true,
                supplierMatchStatus: $supplierSelection['match_status'],
                reason: 'Supplier stock is stale.',
                issueCode: 'supplier_stock_stale',
                issueMessage: 'Supplier stock is stale.',
            );
        }

        if ($supplierSelection['availability_status'] !== null
            && $this->availabilityEvaluator->classify($supplierSelection['availability_status']) === 'unknown') {
            return $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'unavailable',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $supplierSelection['numeric_quantity'],
                supplierAvailability: $supplierSelection['availability_status'],
                supplierName: $supplierSelection['supplier_name'],
                isStale: $isStale,
                supplierMatchStatus: $supplierSelection['match_status'],
                reason: 'Supplier availability is unknown.',
                issueCode: 'supplier_availability_unknown',
                issueMessage: 'Supplier availability is unknown.',
            );
        }

        $unknownSupplierAvailability = $this->findUnknownSupplierAvailability($variant);

        if ($unknownSupplierAvailability !== null) {
            return $this->buildResult(
                exportable: false,
                sourceType: null,
                quantity: 0,
                stockStatus: 'unavailable',
                deliveryClass: VarleStockEvaluator::CLASS_BLOCKED,
                deliveryText: null,
                localQuantity: $localQuantity,
                supplierQuantity: $unknownSupplierAvailability['numeric_quantity'],
                supplierAvailability: $unknownSupplierAvailability['availability_status'],
                supplierName: $unknownSupplierAvailability['supplier_name'],
                isStale: $unknownSupplierAvailability['is_stale'],
                supplierMatchStatus: $unknownSupplierAvailability['match_status'],
                reason: 'Supplier availability is unknown.',
                issueCode: 'supplier_availability_unknown',
                issueMessage: 'Supplier availability is unknown.',
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
            supplierQuantity: $supplierSelection['numeric_quantity'],
            supplierAvailability: $supplierSelection['availability_status'],
            supplierName: $supplierSelection['supplier_name'],
            isStale: $isStale,
            supplierMatchStatus: $supplierSelection['match_status'],
            reason: 'No stock available from Shopify or supplier.',
            issueCode: 'no_stock_anywhere',
            issueMessage: 'No exportable stock is available from Shopify or supplier sources.',
        );
    }

    /**
     * @return array{
     *     quantity: int,
     *     numeric_quantity: ?int,
     *     raw_supplier_quantity: int,
     *     supplier_id: ?int,
     *     supplier_name: ?string,
     *     delivery_text: ?string,
     *     match_status: ?string,
     *     availability_status: ?string,
     *     is_stale: bool,
     *     source_type: ?string
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
                    && $supplierProduct->availability_status !== SupplierProduct::AVAILABILITY_UNAVAILABLE
                    && $supplierProduct->availability_status !== SupplierProduct::AVAILABILITY_MISSING_FROM_FEED;
            })
            ->sortBy(fn (SupplierProduct $supplierProduct): int => (int) optional($supplierProduct->supplier)->stock_priority)
            ->values();

        $numericCandidates = $candidates->filter(
            fn (SupplierProduct $supplierProduct): bool => $supplierProduct->stock_quantity !== null
                && $supplierProduct->stock_quantity > 0,
        );

        $rawSupplierQuantity = (int) $numericCandidates->sum('stock_quantity');

        $usableNumeric = $numericCandidates->first(
            fn (SupplierProduct $supplierProduct): bool => ! $supplierProduct->isStale(),
        );

        if ($usableNumeric instanceof SupplierProduct) {
            return $this->selectionFromSupplierProduct(
                $usableNumeric,
                quantity: (int) $usableNumeric->stock_quantity,
                numericQuantity: (int) $usableNumeric->stock_quantity,
                rawSupplierQuantity: $rawSupplierQuantity,
                isStale: false,
                sourceType: self::SOURCE_SUPPLIER,
            );
        }

        $fallbackCandidates = $candidates->filter(function (SupplierProduct $supplierProduct): bool {
            if ($supplierProduct->stock_quantity !== null) {
                return false;
            }

            return $this->availabilityEvaluator->isTruthy(
                $this->resolveSupplierAvailabilityLabel($supplierProduct),
            );
        });

        $usableFallback = $fallbackCandidates->first(
            fn (SupplierProduct $supplierProduct): bool => ! $supplierProduct->isStale(),
        );

        if ($usableFallback instanceof SupplierProduct) {
            $fallbackQuantity = $usableFallback->supplier?->availability_fallback_quantity
                ?? self::DEFAULT_AVAILABILITY_FALLBACK_QUANTITY;

            return $this->selectionFromSupplierProduct(
                $usableFallback,
                quantity: max(1, (int) $fallbackQuantity),
                numericQuantity: null,
                rawSupplierQuantity: $rawSupplierQuantity,
                isStale: false,
                sourceType: self::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK,
            );
        }

        $firstNumeric = $numericCandidates->first();
        $firstFallback = $fallbackCandidates->first();
        $first = $firstNumeric ?? $firstFallback ?? $candidates->first();

        return [
            'quantity' => 0,
            'numeric_quantity' => $first?->stock_quantity,
            'raw_supplier_quantity' => $rawSupplierQuantity,
            'supplier_id' => $first?->supplier_id,
            'supplier_name' => $first?->supplier?->name,
            'delivery_text' => $first?->supplier?->in_stock_delivery_text,
            'match_status' => $first?->match_status,
            'availability_status' => $this->resolveSupplierAvailabilityLabel($first),
            'is_stale' => $rawSupplierQuantity > 0 || $fallbackCandidates->isNotEmpty(),
            'source_type' => null,
        ];
    }

    /**
     * @return array{
     *     quantity: int,
     *     numeric_quantity: ?int,
     *     raw_supplier_quantity: int,
     *     supplier_id: ?int,
     *     supplier_name: ?string,
     *     delivery_text: ?string,
     *     match_status: ?string,
     *     availability_status: ?string,
     *     is_stale: bool,
     *     source_type: ?string
     * }
     */
    private function selectionFromSupplierProduct(
        SupplierProduct $supplierProduct,
        int $quantity,
        ?int $numericQuantity,
        int $rawSupplierQuantity,
        bool $isStale,
        ?string $sourceType,
    ): array {
        return [
            'quantity' => $quantity,
            'numeric_quantity' => $numericQuantity,
            'raw_supplier_quantity' => $rawSupplierQuantity,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_name' => $supplierProduct->supplier?->name,
            'delivery_text' => $supplierProduct->supplier?->in_stock_delivery_text,
            'match_status' => $supplierProduct->match_status,
            'availability_status' => $this->resolveSupplierAvailabilityLabel($supplierProduct),
            'is_stale' => $isStale,
            'source_type' => $sourceType,
        ];
    }

    private function resolveSupplierAvailabilityLabel(?SupplierProduct $supplierProduct): ?string
    {
        if (! $supplierProduct instanceof SupplierProduct) {
            return null;
        }

        $rawAvailability = data_get($supplierProduct->raw_payload, 'availability');

        if (is_string($rawAvailability) && $rawAvailability !== '') {
            return $rawAvailability;
        }

        return $supplierProduct->availability_status;
    }

    /**
     * @return array{
     *     numeric_quantity: ?int,
     *     availability_status: ?string,
     *     supplier_name: ?string,
     *     match_status: ?string,
     *     is_stale: bool
     * }|null
     */
    private function findUnknownSupplierAvailability(ProductVariant $variant): ?array
    {
        $unknown = $variant->supplierProducts->first(function (SupplierProduct $supplierProduct): bool {
            $supplier = $supplierProduct->supplier;

            if (! $supplierProduct->enabled
                || ! $supplier instanceof Supplier
                || ! $supplier->enabled
                || $supplierProduct->product_variant_id === null
                || $supplierProduct->match_status !== SupplierProduct::MATCH_STATUS_MATCHED) {
                return false;
            }

            return $this->availabilityEvaluator->classify(
                $this->resolveSupplierAvailabilityLabel($supplierProduct),
            ) === 'unknown';
        });

        if (! $unknown instanceof SupplierProduct) {
            return null;
        }

        return [
            'numeric_quantity' => $unknown->stock_quantity,
            'availability_status' => $this->resolveSupplierAvailabilityLabel($unknown),
            'supplier_name' => $unknown->supplier?->name,
            'match_status' => $unknown->match_status,
            'is_stale' => $unknown->isStale(),
        ];
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
        ?int $supplierQuantity,
        ?string $supplierAvailability,
        ?string $supplierName,
        bool $isStale,
        ?string $supplierMatchStatus,
        ?int $supplierId = null,
        bool $usedAvailabilityFallback = false,
        ?int $availabilityFallbackQuantity = null,
        ?string $reason = null,
        ?string $issueCode = null,
        ?string $issueMessage = null,
    ): array {
        if ($exportable && $quantity <= 0) {
            $exportable = false;
            $sourceType = null;
            $stockStatus = 'unavailable';
            $deliveryClass = VarleStockEvaluator::CLASS_BLOCKED;
            $deliveryText = null;
            $reason ??= 'Resolved quantity must be greater than zero.';
            $issueCode ??= 'no_stock_anywhere';
            $issueMessage ??= 'Resolved quantity must be greater than zero.';
        }

        return [
            'exportable' => $exportable,
            'available' => $exportable,
            'source_type' => $sourceType,
            'source' => $sourceType,
            'supplier_id' => $supplierId,
            'quantity' => max(0, $quantity),
            'stock_status' => $stockStatus,
            'delivery_text' => $deliveryText,
            'delivery_class' => $deliveryClass,
            'reason' => $reason,
            'issue_code' => $issueCode,
            'issue_message' => $issueMessage,
            'local_quantity' => $localQuantity,
            'supplier_quantity' => $supplierQuantity,
            'supplier_availability' => $supplierAvailability,
            'used_availability_fallback' => $usedAvailabilityFallback,
            'availability_fallback_quantity' => $availabilityFallbackQuantity,
            'supplier_name' => $supplierName,
            'is_stale' => $isStale,
            'supplier_match_status' => $supplierMatchStatus,
            'delivery_days_min' => match ($sourceType) {
                self::SOURCE_SHOPIFY => 1,
                self::SOURCE_SUPPLIER, self::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK => 5,
                self::SOURCE_BACKORDER => 5,
                default => null,
            },
            'delivery_days_max' => match ($sourceType) {
                self::SOURCE_SHOPIFY => 2,
                self::SOURCE_SUPPLIER, self::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK => 10,
                self::SOURCE_BACKORDER => 10,
                default => null,
            },
        ];
    }

    private function variantAllowsBackorder(ProductVariant $variant): bool
    {
        if ($variant->backorder_allowed) {
            return true;
        }

        return mb_strtoupper(trim((string) ($variant->inventory_policy ?? ''))) === 'CONTINUE';
    }
}
