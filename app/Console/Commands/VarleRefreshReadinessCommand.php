<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Varle\VarleReadinessService;
use Illuminate\Console\Command;

class VarleRefreshReadinessCommand extends Command
{
    protected $signature = 'varle:refresh-readiness {--product= : Refresh readiness for a single product handle}';

    protected $description = 'Refresh cached Varle export readiness fields on products';

    public function handle(VarleReadinessService $readinessService): int
    {
        $handle = $this->option('product');

        if (filled($handle)) {
            $product = \App\Models\Product::query()->where('handle', $handle)->first();

            if ($product === null) {
                $this->components->error("Product not found for handle: {$handle}");

                return self::FAILURE;
            }

            $readinessService->cache($product);
            $product->refresh();
            $this->components->info("Refreshed readiness for {$handle}: ready=".($product->varle_is_ready ? 'yes' : 'no').", issues={$product->varle_issue_count}");

            return self::SUCCESS;
        }

        $count = $readinessService->refreshAll();
        $this->components->info("Refreshed Varle readiness for {$count} product(s).");

        return self::SUCCESS;
    }
}
