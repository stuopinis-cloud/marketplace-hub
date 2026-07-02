<?php

namespace App\Services\Marketplace\Varle;

class VarleExportResult
{
    /**
     * @param  array<int, string>  $debugLines
     */
    public function __construct(
        public readonly int $syncJobId,
        public readonly int $exportedVariants,
        public readonly int $skippedVariants,
        public readonly string $feedPath,
        public readonly string $publicUrl,
        public readonly array $debugLines = [],
    ) {}
}
