<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\SupplierAvailabilityEvaluator;

class SupplierCsvStockResolver
{
    public function __construct(
        private readonly SupplierAvailabilityEvaluator $availabilityEvaluator = new SupplierAvailabilityEvaluator,
    ) {}

    /**
     * @return array{0: ?int, 1: string}
     */
    public function resolve(?string $stockRaw, ?string $availabilityRaw, Supplier $supplier): array
    {
        if ($stockRaw !== null && $stockRaw !== '' && is_numeric($stockRaw)) {
            $quantity = max(0, (int) $stockRaw);

            return [
                $quantity > 0 ? $quantity : 0,
                $quantity > 0
                    ? SupplierProduct::AVAILABILITY_AVAILABLE
                    : SupplierProduct::AVAILABILITY_UNAVAILABLE,
            ];
        }

        if ($this->isTruthyAvailability($availabilityRaw, $supplier)) {
            return [null, SupplierProduct::AVAILABILITY_AVAILABLE];
        }

        return [0, SupplierProduct::AVAILABILITY_UNAVAILABLE];
    }

    public function isTruthyAvailability(?string $value, Supplier $supplier): bool
    {
        $normalized = $this->availabilityEvaluator->normalize($value);

        if ($normalized === '') {
            return false;
        }

        $truthy = array_merge(
            SupplierAvailabilityEvaluator::TRUTHY_VALUES,
            SupplierCsvConfig::truthyValues($supplier),
        );

        if (in_array($normalized, $truthy, true)) {
            return true;
        }

        $falsy = array_merge(
            SupplierAvailabilityEvaluator::FALSY_VALUES,
            SupplierCsvConfig::falsyValues($supplier),
        );

        if (in_array($normalized, $falsy, true)) {
            return false;
        }

        return false;
    }
}
