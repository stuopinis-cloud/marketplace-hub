<?php

namespace App\Jobs;

use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshVarleReadinessJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>|null  $productIds
     */
    public function __construct(
        public readonly int $syncJobId,
        public readonly ?array $productIds = null,
        public readonly int $chunkSize = 100,
    ) {}

    public function handle(VarleReadinessRefreshService $refreshService): void
    {
        $refreshService->run($this->syncJobId, $this->productIds, $this->chunkSize);
    }
}
