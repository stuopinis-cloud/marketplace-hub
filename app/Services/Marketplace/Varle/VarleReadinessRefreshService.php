<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\SyncJobStatus;
use App\Jobs\RefreshVarleReadinessJob;
use App\Models\Product;
use App\Models\SyncJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

class VarleReadinessRefreshService
{
    public const string LOCK_KEY = 'varle-readiness-refresh';

    public const int LOCK_SECONDS = 7200;

    public function __construct(
        private readonly VarleReadinessService $readinessService,
    ) {}

    public function dispatch(?array $productIds = null, int $chunkSize = 100): VarleReadinessRefreshDispatchResult
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(3, function () use ($productIds, $chunkSize): VarleReadinessRefreshDispatchResult {
            if ($this->findActiveJob() instanceof SyncJob) {
                return VarleReadinessRefreshDispatchResult::alreadyRunning();
            }

            $syncJob = SyncJob::query()->create([
                'type' => 'readiness',
                'source' => 'marketplace',
                'channel' => 'varle',
                'status' => SyncJobStatus::Pending,
                'total_items' => $this->countProducts($productIds),
                'context' => [
                    'chunk_size' => $chunkSize,
                    'product_ids' => $productIds,
                    'stage' => 'queued',
                ],
            ]);

            RefreshVarleReadinessJob::dispatch($syncJob->id, $productIds, $chunkSize);

            return VarleReadinessRefreshDispatchResult::dispatched($syncJob);
        });
    }

    public function runSynchronously(int $chunkSize = 100, ?array $productIds = null): int
    {
        if ($this->findActiveJob() instanceof SyncJob) {
            throw new \RuntimeException('A Varle readiness refresh is already running.');
        }

        $syncJob = SyncJob::query()->create([
            'type' => 'readiness',
            'source' => 'marketplace',
            'channel' => 'varle',
            'status' => SyncJobStatus::Pending,
            'total_items' => $this->countProducts($productIds),
            'context' => [
                'chunk_size' => $chunkSize,
                'product_ids' => $productIds,
                'stage' => 'queued',
            ],
        ]);

        $this->run($syncJob->id, $productIds, $chunkSize);

        return (int) $syncJob->fresh()?->success_items;
    }

    public function run(int $syncJobId, ?array $productIds = null, int $chunkSize = 100): void
    {
        $syncJob = SyncJob::query()->findOrFail($syncJobId);
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->markFailed($syncJob, 'A Varle readiness refresh is already running.');

            return;
        }

        try {
            $this->execute($syncJob, $productIds, $chunkSize);
        } finally {
            $lock->release();
        }
    }

    public function findActiveJob(): ?SyncJob
    {
        return SyncJob::query()
            ->where('type', 'readiness')
            ->where('source', 'marketplace')
            ->where('channel', 'varle')
            ->whereIn('status', [SyncJobStatus::Pending, SyncJobStatus::Running])
            ->latest('id')
            ->first();
    }

    private function execute(SyncJob $syncJob, ?array $productIds, int $chunkSize): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
            'context' => array_merge($syncJob->context ?? [], [
                'stage' => 'refreshing_readiness',
            ]),
        ]);

        $context = $this->readinessService->createRunContext();
        $success = 0;
        $failed = 0;
        $index = 0;

        try {
            $this->productsQuery($productIds)
                ->with([
                    'variants.inventoryLevels',
                    'variants.supplierProducts.supplier',
                    'images',
                    'sourceCategories',
                ])
                ->orderBy('id')
                ->chunkById($chunkSize, function ($products) use (
                    $context,
                    $syncJob,
                    &$success,
                    &$failed,
                    &$index,
                ): void {
                    foreach ($products as $product) {
                        $index++;

                        try {
                            $this->readinessService->cache($product, context: $context);
                            $success++;
                        } catch (Throwable $exception) {
                            $failed++;
                        }

                        $syncJob->update([
                            'success_items' => $success,
                            'failed_items' => $failed,
                            'heartbeat_at' => now(),
                            'context' => array_merge($syncJob->context ?? [], [
                                'current_product_id' => $product->id,
                                'current_product_handle' => $product->handle,
                                'current_product_index' => $index,
                                'stage' => 'refreshing_readiness',
                            ]),
                        ]);
                    }
                });

            $status = $failed > 0 && $success === 0
                ? SyncJobStatus::Failed
                : ($failed > 0 ? SyncJobStatus::Partial : SyncJobStatus::Completed);

            $syncJob->update([
                'status' => $status,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'success_items' => $success,
                'failed_items' => $failed,
                'error_message' => $failed > 0 && $success === 0
                    ? 'Varle readiness refresh failed for all products.'
                    : null,
                'context' => array_merge($syncJob->context ?? [], [
                    'stage' => 'completed',
                ]),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($syncJob, $exception->getMessage(), $success, $failed);

            throw $exception;
        }
    }

    private function markFailed(SyncJob $syncJob, string $message, int $success = 0, int $failed = 0): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'success_items' => $success,
            'failed_items' => $failed,
            'error_message' => $message,
            'context' => array_merge($syncJob->context ?? [], [
                'stage' => 'failed',
            ]),
        ]);
    }

    /**
     * @return Builder<Product>
     */
    private function productsQuery(?array $productIds): Builder
    {
        $query = Product::query();

        if ($productIds !== null && $productIds !== []) {
            $query->whereIn('id', $productIds);
        }

        return $query;
    }

    private function countProducts(?array $productIds): int
    {
        return $this->productsQuery($productIds)->count();
    }
}
