<?php

namespace App\Services\Marketplace;

class MarketplaceValidationResult
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public static function valid(array $warnings = []): self
    {
        return new self(isValid: true, warnings: $warnings);
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(isValid: false, errors: $errors, warnings: $warnings);
    }

    public function message(): string
    {
        return implode('; ', $this->errors);
    }
}
