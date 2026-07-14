<?php

namespace App\Services\Marketplace\Varle;

class VarleXmlFeedValidationResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
    ) {}

    public static function valid(): self
    {
        return new self(true);
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }

    public function message(): string
    {
        return implode(' ', $this->errors);
    }
}
