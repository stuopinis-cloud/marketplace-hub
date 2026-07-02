<?php

namespace App\Services\Automation;

class DailyMarketplaceSyncResult
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $message,
        public readonly array $summary = [],
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function success(array $summary = [], string $message = 'Daily marketplace sync completed successfully.'): self
    {
        return new self(true, $message, $summary);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function failed(string $message, array $summary = []): self
    {
        return new self(false, $message, $summary);
    }
}
