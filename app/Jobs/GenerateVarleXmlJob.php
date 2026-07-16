<?php

namespace App\Jobs;

use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateVarleXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly bool $debug = false,
    ) {}

    public function handle(VarleFeedPublisher $publisher): void
    {
        $lock = MarketplaceJobLock::make(MarketplaceJobLock::VARLE_EXPORT);

        if (! $lock->get()) {
            return;
        }

        try {
            $publisher->publish(debug: $this->debug);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceJobLock::forceRelease(MarketplaceJobLock::VARLE_EXPORT);
    }
}
