<?php

namespace App\Jobs;

use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSupplierStockJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $supplierCode,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(SupplierSyncManager $manager): void
    {
        $lockKey = MarketplaceJobLock::forSupplier($this->supplierCode);
        $lock = MarketplaceJobLock::make($lockKey);

        if (! $lock->get()) {
            return;
        }

        try {
            $manager->sync(
                $this->supplierCode,
                new SupplierSyncOptions(dryRun: $this->dryRun),
            );
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceJobLock::forceRelease(MarketplaceJobLock::forSupplier($this->supplierCode));
    }
}
