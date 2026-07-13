<?php

namespace App\Services\Suppliers;

class SupplierAvailabilityEvaluator
{
    public const array TRUTHY_VALUES = [
        'yes',
        'true',
        'in stock',
        'in_stock',
        'available',
        'yra',
        'sandelyje',
    ];

    public const array FALSY_VALUES = [
        'no',
        'false',
        'out of stock',
        'out_of_stock',
        'unavailable',
        'nėra',
        'nera',
    ];

    public function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    public function isTruthy(?string $value): bool
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::TRUTHY_VALUES, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSY_VALUES, true)) {
            return false;
        }

        return false;
    }

    public function isFalsy(?string $value): bool
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::FALSY_VALUES, true);
    }

    public function classify(?string $value): string
    {
        if ($this->isTruthy($value)) {
            return 'truthy';
        }

        if ($this->isFalsy($value)) {
            return 'falsy';
        }

        return 'unknown';
    }
}
