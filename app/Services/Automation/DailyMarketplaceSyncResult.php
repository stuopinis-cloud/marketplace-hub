<?php

namespace App\Services\Automation;

class DailyMarketplaceSyncResult
{
    public const string OUTCOME_COMPLETED = 'completed';

    public const string OUTCOME_PARTIAL = 'partial';

    public const string OUTCOME_FAILED = 'failed';

    public const string OUTCOME_CANCELLED = 'cancelled';

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $message,
        public readonly array $summary = [],
        public readonly string $outcome = self::OUTCOME_COMPLETED,
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $warnings
     */
    public static function success(
        array $summary = [],
        string $message = 'Daily marketplace sync completed successfully.',
        array $warnings = [],
    ): self {
        return new self(
            successful: true,
            message: $message,
            summary: $summary,
            outcome: self::OUTCOME_COMPLETED,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $warnings
     */
    public static function partial(
        string $message,
        array $summary = [],
        array $warnings = [],
    ): self {
        return new self(
            successful: true,
            message: $message,
            summary: $summary,
            outcome: self::OUTCOME_PARTIAL,
            warnings: $warnings !== [] ? $warnings : [$message],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function failed(string $message, array $summary = []): self
    {
        return new self(
            successful: false,
            message: $message,
            summary: $summary,
            outcome: self::OUTCOME_FAILED,
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function cancelled(string $message, array $summary = []): self
    {
        return new self(
            successful: false,
            message: $message,
            summary: $summary,
            outcome: self::OUTCOME_CANCELLED,
        );
    }

    public function isPartial(): bool
    {
        return $this->outcome === self::OUTCOME_PARTIAL;
    }
}
