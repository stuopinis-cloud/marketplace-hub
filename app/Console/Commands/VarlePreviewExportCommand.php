<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Console\Command;

class VarlePreviewExportCommand extends Command
{
    protected $signature = 'varle:preview-export';

    protected $description = 'Preview Varle XML export counts without writing the feed file';

    public function handle(VarleXmlExporter $exporter): int
    {
        $preview = $exporter->preview();

        $this->components->info('Varle export preview');
        $this->newLine();

        $this->line('Products that would be exported: '.$preview->exportableProducts);
        $this->line('Variants that would be exported: '.$preview->exportableVariants);
        $this->line('Skipped variants: '.$preview->skippedVariants);
        $this->newLine();

        $this->components->info('Export approval / gating');
        $this->line('Pending review products: '.$preview->pendingReviewProducts);
        $this->line('Excluded products: '.$preview->excludedProducts);
        $this->line('Skipped due to disabled category mapping: '.$preview->categoryDisabledProducts);
        $this->line('Unpublished products: '.$preview->unpublishedProducts);
        $this->newLine();

        $this->components->info('Data quality');
        $this->line('Missing barcode variants: '.$preview->missingBarcodeVariants);
        $this->line('Missing category mapping products: '.$preview->missingCategoryProducts);
        $this->line('Fallback category products: '.$preview->fallbackCategoryProducts);
        $this->newLine();

        if ($preview->topSkipReasons !== []) {
            $this->components->info('Top skip reasons');
            foreach ($preview->topSkipReasons as $reason => $count) {
                $this->line(sprintf('- %s: %d', $reason, $count));
            }
        }

        return self::SUCCESS;
    }
}
