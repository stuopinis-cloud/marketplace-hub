<?php

namespace App\Services\Marketplace\Pigu;

class PiguExportOptions
{
    public function __construct(
        public readonly bool $easyOnly = false,
        public readonly bool $mappedOnly = false,
        public readonly ?string $collection = null,
        public readonly bool $allValid = false,
    ) {}

    public static function fromArray(array $options): self
    {
        return new self(
            easyOnly: (bool) ($options['easy_only'] ?? false),
            mappedOnly: (bool) ($options['mapped_only'] ?? false),
            collection: filled($options['collection'] ?? null) ? trim((string) $options['collection']) : null,
            allValid: (bool) ($options['all_valid'] ?? false),
        );
    }
}
