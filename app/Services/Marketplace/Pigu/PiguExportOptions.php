<?php

namespace App\Services\Marketplace\Pigu;

class PiguExportOptions
{
    public function __construct(
        public readonly bool $easyOnly = false,
        public readonly bool $mappedOnly = false,
        public readonly ?string $collection = null,
        public readonly bool $allValid = false,
        public readonly bool $preview = false,
        public readonly ?int $previewSupplierId = null,
    ) {}

    public static function fromArray(array $options): self
    {
        return new self(
            easyOnly: (bool) ($options['easy_only'] ?? false),
            mappedOnly: (bool) ($options['mapped_only'] ?? false),
            collection: filled($options['collection'] ?? null) ? trim((string) $options['collection']) : null,
            allValid: (bool) ($options['all_valid'] ?? false),
            preview: (bool) ($options['preview'] ?? false),
            previewSupplierId: isset($options['preview_supplier_id'])
                ? (int) $options['preview_supplier_id']
                : null,
        );
    }

    public function isPreview(): bool
    {
        return $this->preview && $this->previewSupplierId !== null;
    }

    /**
     * Dry-run options for a single supplier contribution preview.
     */
    public static function supplierPreview(int $supplierId, bool $allValid = true): self
    {
        return new self(
            allValid: $allValid,
            preview: true,
            previewSupplierId: $supplierId,
        );
    }
}
