<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Decimal-safe price calculation for supplier recommended retail → Pigu offer price.
 */
class PiguSupplierPriceCalculator
{
    public function calculate(?float $recommendedPrice, Supplier $supplier): ?float
    {
        if ($recommendedPrice === null || $recommendedPrice <= 0) {
            return null;
        }

        $price = BigDecimal::of((string) $recommendedPrice);
        $multiplier = BigDecimal::of((string) PiguSupplierOfferConfig::priceMultiplier($supplier));
        $price = $price->multipliedBy($multiplier);

        if (! PiguSupplierOfferConfig::priceIncludesVat($supplier)) {
            $vatRate = BigDecimal::of((string) PiguSupplierOfferConfig::vatRate($supplier))
                ->dividedBy(100, 8, RoundingMode::HalfUp);
            $price = $price->multipliedBy(BigDecimal::one()->plus($vatRate));
        }

        $price = $this->applyRounding($price, PiguSupplierOfferConfig::priceRounding($supplier));

        $result = (float) (string) $price->toScale(2, RoundingMode::HalfUp);

        return $result > 0 ? $result : null;
    }

    private function applyRounding(BigDecimal $price, ?string $rounding): BigDecimal
    {
        return match ($rounding) {
            '0.10', '0.1' => $price
                ->dividedBy('0.10', 0, RoundingMode::HalfUp)
                ->multipliedBy('0.10'),
            '1.00', '1' => $price
                ->toScale(0, RoundingMode::HalfUp),
            '0.05' => $price
                ->dividedBy('0.05', 0, RoundingMode::HalfUp)
                ->multipliedBy('0.05'),
            default => $price->toScale(2, RoundingMode::HalfUp),
        };
    }
}
