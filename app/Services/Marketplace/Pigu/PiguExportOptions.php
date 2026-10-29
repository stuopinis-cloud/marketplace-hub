<?php

namespace App\Services\Marketplace\Pigu;

class PiguExportOptions
{
    public const string PREVIEW_SCOPE_CONTRIBUTION = 'contribution';

    public const string PREVIEW_SCOPE_COMBINED = 'combined';

    public function __construct(
        public readonly bool $easyOnly = false,
        public readonly bool $mappedOnly = false,
        public readonly ?string $collection = null,
        public readonly bool $allValid = false,
        public readonly bool $preview = false,
        public readonly ?int $previewSupplierId = null,
        public readonly string $previewScope = self::PREVIEW_SCOPE_CONTRIBUTION,
    ) {}

    public static function fromArray(array $options): self
    {
        $scope = (string) ($options['preview_scope'] ?? self::PREVIEW_SCOPE_CONTRIBUTION);

        return new self(
            easyOnly: (bool) ($options['easy_only'] ?? false),
            mappedOnly: (bool) ($options['mapped_only'] ?? false),
            collection: filled($options['collection'] ?? null) ? trim((string) $options['collection']) : null,
            allValid: (bool) ($options['all_valid'] ?? false),
            preview: (bool) ($options['preview'] ?? false),
            previewSupplierId: isset($options['preview_supplier_id'])
                ? (int) $options['preview_supplier_id']
                : null,
            previewScope: in_array($scope, [self::PREVIEW_SCOPE_CONTRIBUTION, self::PREVIEW_SCOPE_COMBINED], true)
                ? $scope
                : self::PREVIEW_SCOPE_CONTRIBUTION,
        );
    }

    public function isPreview(): bool
    {
        return $this->preview && $this->previewSupplierId !== null;
    }

    public function isCombinedPreview(): bool
    {
        return $this->isPreview() && $this->previewScope === self::PREVIEW_SCOPE_COMBINED;
    }

    public function isContributionPreview(): bool
    {
        return $this->isPreview() && $this->previewScope === self::PREVIEW_SCOPE_CONTRIBUTION;
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
            previewScope: self::PREVIEW_SCOPE_CONTRIBUTION,
        );
    }

    /**
     * Full combined feed dry-run with the selected supplier force-included.
     */
    public static function combinedPreview(int $supplierId, bool $allValid = true): self
    {
        return new self(
            allValid: $allValid,
            preview: true,
            previewSupplierId: $supplierId,
            previewScope: self::PREVIEW_SCOPE_COMBINED,
        );
    }
}
