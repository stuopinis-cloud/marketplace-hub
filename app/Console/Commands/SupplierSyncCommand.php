<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Console\Command;

class SupplierSyncCommand extends Command
{
    protected $signature = 'supplier:sync
                            {supplier : Supplier code, e.g. mtac or helik}
                            {--dry-run : Parse and match without writing supplier stock}
                            {--limit= : Process only the first N parsed feed entries}
                            {--sku= : Process only the selected supplier SKU}';

    protected $description = 'Sync supplier stock from a configured supplier feed';

    public function handle(SupplierSyncManager $manager): int
    {
        $supplierCode = (string) $this->argument('supplier');
        $options = new SupplierSyncOptions(
            dryRun: (bool) $this->option('dry-run'),
            limit: filled($this->option('limit')) ? (int) $this->option('limit') : null,
            sku: filled($this->option('sku')) ? (string) $this->option('sku') : null,
            verbose: $this->output->isVerbose(),
        );

        $this->components->info(sprintf('Starting supplier sync for %s%s...', $supplierCode, $options->dryRun ? ' (dry-run)' : ''));

        try {
            $result = $manager->sync($supplierCode, $options);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Sync job ID: '.$result->syncJobId);
        $this->line('Parsed: '.$result->parsed);
        $this->line('Matched: '.$result->matched);
        $this->line('Unmatched: '.$result->unmatched);
        $this->line('Ambiguous: '.$result->ambiguous);
        $this->line('Duplicate supplier SKU: '.$result->duplicateSupplierSku);
        $this->line('Positive stock: '.$result->positiveStock);
        $this->line('Zero stock: '.$result->zeroStock);
        $this->line('Failed rows: '.$result->failedRows);
        $this->line('Missing from feed: '.$result->missingFromFeed);

        if ($result->duplicateShopifySku > 0) {
            $this->line('Duplicate Shopify SKU: '.$result->duplicateShopifySku);
        }

        if ($result->missingSku > 0) {
            $this->line('Missing SKU rows: '.$result->missingSku);
        }

        if ($result->missingQuantity > 0) {
            $this->line('Missing quantity rows: '.$result->missingQuantity);
        }

        if ($this->output->isVerbose()) {
            $context = \App\Models\SyncJob::query()->find($result->syncJobId)?->context ?? [];
            $fallbackCandidates = (int) data_get($context, 'stats.availability_fallback_candidates', 0);

            if ($fallbackCandidates > 0) {
                $this->line('Availability fallback candidates: '.$fallbackCandidates);
            }
        }

        $this->components->info('Supplier sync finished.');

        return self::SUCCESS;
    }
}
