<?php

namespace App\Services\Marketplace;

class CategoryMappingCsvImportResult
{
    /**
     * @param  array<int, array<string, mixed>>  $previewRows
     * @param  array<int, array<string, mixed>>  $failedRows
     */
    public function __construct(
        public int $totalRows,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $skipped,
        public int $failed,
        public bool $dryRun,
        public array $previewRows = [],
        public array $failedRows = [],
        public ?string $failedCsvRelativePath = null,
    ) {}

    public function summaryMessage(): string
    {
        $prefix = $this->dryRun ? 'Dry run complete. ' : 'Import complete. ';

        return $prefix.sprintf(
            'Total: %d, created: %d, updated: %d, unchanged: %d, skipped: %d, failed: %d.',
            $this->totalRows,
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->skipped,
            $this->failed,
        );
    }
}
