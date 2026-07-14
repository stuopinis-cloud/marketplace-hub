<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Illuminate\Console\Command;

class VarleRefreshReadinessCommand extends Command
{
    protected $signature = 'varle:refresh-readiness
                            {--product= : Refresh readiness for a single product handle}
                            {--queue : Dispatch the refresh to the queue}
                            {--chunk=100 : Number of products processed per chunk}';

    protected $description = 'Refresh cached Varle export readiness fields on products';

    public function handle(
        VarleReadinessService $readinessService,
        VarleReadinessRefreshService $refreshService,
    ): int {
        $handle = $this->option('product');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (filled($handle)) {
            $product = Product::query()->where('handle', $handle)->first();

            if ($product === null) {
                $this->components->error("Product not found for handle: {$handle}");

                return self::FAILURE;
            }

            $readinessService->cache($product);
            $product->refresh();
            $this->components->info("Refreshed readiness for {$handle}: ready=".($product->varle_is_ready ? 'yes' : 'no').", issues={$product->varle_issue_count}");

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $result = $refreshService->dispatch(chunkSize: $chunkSize);

            if ($result->alreadyRunning) {
                $this->components->warn($result->message ?? 'A Varle readiness refresh is already running.');

                return self::FAILURE;
            }

            $this->components->info('Varle readiness refresh dispatched to the queue.');
            $this->line('Sync job ID: '.$result->syncJob?->id);

            return self::SUCCESS;
        }

        if ($refreshService->findActiveJob() !== null) {
            $this->components->warn('A Varle readiness refresh is already running.');

            return self::FAILURE;
        }

        $count = $refreshService->runSynchronously($chunkSize);
        $this->components->info("Refreshed Varle readiness for {$count} product(s).");

        return self::SUCCESS;
    }
}
