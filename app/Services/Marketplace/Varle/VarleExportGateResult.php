<?php

namespace App\Services\Marketplace\Varle;

class VarleExportGateResult
{
    /**
     * @param  array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }|null  $categoryExplanation
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $skipMessage = null,
        public readonly ?array $categoryExplanation = null,
        public readonly ?bool $categoryMappingExportEnabled = null,
    ) {}

    public static function allow(?array $categoryExplanation, ?bool $categoryMappingExportEnabled = null): self
    {
        return new self(true, null, $categoryExplanation, $categoryMappingExportEnabled);
    }

    public static function deny(string $message, ?array $categoryExplanation = null, ?bool $categoryMappingExportEnabled = null): self
    {
        return new self(false, $message, $categoryExplanation, $categoryMappingExportEnabled);
    }
}
