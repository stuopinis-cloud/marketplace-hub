<?php

namespace Tests\Unit\Services\Marketplace\Pigu;

use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierPriceCalculator;
use Tests\TestCase;

class PiguSupplierPriceCalculatorTest extends TestCase
{
    public function test_adds_vat_when_source_excludes_vat(): void
    {
        $supplier = new Supplier([
            'config' => [
                PiguSupplierOfferConfig::CONFIG_KEY => [
                    'price_includes_vat' => false,
                    'vat_rate' => 21,
                    'price_multiplier' => 1,
                ],
            ],
        ]);

        $price = (new PiguSupplierPriceCalculator)->calculate(100.00, $supplier);

        $this->assertSame(121.00, $price);
    }

    public function test_keeps_price_when_vat_already_included(): void
    {
        $supplier = new Supplier([
            'config' => [
                PiguSupplierOfferConfig::CONFIG_KEY => [
                    'price_includes_vat' => true,
                    'vat_rate' => 21,
                ],
            ],
        ]);

        $this->assertSame(49.99, (new PiguSupplierPriceCalculator)->calculate(49.99, $supplier));
    }

    public function test_applies_multiplier_before_vat(): void
    {
        $supplier = new Supplier([
            'config' => [
                PiguSupplierOfferConfig::CONFIG_KEY => [
                    'price_includes_vat' => false,
                    'vat_rate' => 21,
                    'price_multiplier' => 1.1,
                ],
            ],
        ]);

        // 100 * 1.1 * 1.21 = 133.10
        $this->assertSame(133.10, (new PiguSupplierPriceCalculator)->calculate(100.00, $supplier));
    }

    public function test_invalid_price_returns_null(): void
    {
        $supplier = new Supplier(['config' => []]);

        $this->assertNull((new PiguSupplierPriceCalculator)->calculate(null, $supplier));
        $this->assertNull((new PiguSupplierPriceCalculator)->calculate(0, $supplier));
        $this->assertNull((new PiguSupplierPriceCalculator)->calculate(-1, $supplier));
    }
}
