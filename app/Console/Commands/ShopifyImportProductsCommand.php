<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyImportOptions;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Sync\ShopifyImportJobGuard;
use Illuminate\Console\Command;
use Throwable;

class ShopifyImportProductsCommand extends Command
{
    protected $signature = 'shopify:import-products
                            {--queue : Dispatch the import to the queue}
                            {--limit= : Import only the first N Shopify products}
                            {--handle= : Import a single Shopify product by handle}
                            {--force : Start even if another Shopify import appears to be running}
                            {--cancel-running : Request cancellation of running Shopify imports}';

    protected $description = 'Import active products from Shopify into the local catalog';

    public function handle(
        ShopifyProductImporter $importer,
        ShopifyImportJobGuard $guard,
    ): int {
        if ($this->option('cancel-running')) {
            $count = $guard->requestCancelRunningImports();

            $this->components->info("Cancellation requested for {$count} running Shopify import(s).");

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $this->components->warn('Queued imports do not yet support --limit or --handle. Dispatching full import job.');

            \App\Jobs\ImportShopifyProductsJob::dispatch();

            $this->components->info('Shopify product import dispatched to the queue.');

            return self::SUCCESS;
        }

        $options = new ShopifyImportOptions(
            limit: filled($this->option('limit')) ? (int) $this->option('limit') : null,
            handle: filled($this->option('handle')) ? (string) $this->option('handle') : null,
            verbose: $this->output->isVerbose(),
            force: (bool) $this->option('force'),
            progressCallback: function (
                int $index,
                string $total,
                string $handle,
                int $variants,
                string $stage,
            ): void {
                $this->line(sprintf(
                    '[%d/%s] %s | variants: %d | stage: %s',
                    $index,
                    $total,
                    $handle,
                    $variants,
                    $stage,
                ));
            },
        );

        if (! $options->force) {
            $staleMarked = $guard->markStaleRunningImportsAsFailed();

            if ($staleMarked > 0) {
                $this->components->warn("Marked {$staleMarked} stale Shopify import(s) as failed.");
            }

            $blocking = $guard->findBlockingRunningImport();

            if ($blocking !== null) {
                $this->components->error(sprintf(
                    'Shopify import #%d is already running (heartbeat: %s, PID: %s). Use --force to bypass or --cancel-running to request cancellation.',
                    $blocking->id,
                    $blocking->heartbeat_at?->toDateTimeString() ?? 'unknown',
                    $blocking->process_id ?? 'unknown',
                ));

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import($options);
        } catch (Throwable $exception) {
            $this->components->error('Shopify product import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Shopify product import completed.');
        $this->line('Sync job ID: '.$result->syncJobId);
        $this->line('Imported products: '.$result->productsImported);
        $this->line('Imported variants: '.$result->variantsImported);
        $this->line('Failed items: '.$result->failedItems);

        return $result->failedItems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
