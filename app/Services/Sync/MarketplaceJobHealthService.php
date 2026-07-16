<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplaceJobHealthService
{
    /**
     * @return array{
     *     queue_connection: string,
     *     pending_jobs: ?int,
     *     failed_jobs: ?int,
     *     running_jobs: int,
     *     stale_jobs: int,
     *     latest_shopify_import: ?SyncJob,
     *     latest_varle_export: ?SyncJob,
     *     latest_daily_sync: ?SyncJob,
     *     worker_hint: string
     * }
     */
    public function snapshot(): array
    {
        $health = app(SyncJobHealthService::class);

        $running = SyncJob::query()
            ->where('status', SyncJobStatus::Running)
            ->orderByDesc('id')
            ->get();

        $stale = $running->filter(fn (SyncJob $job): bool => $health->isStuck($job))->values();

        return [
            'queue_connection' => (string) config('queue.default'),
            'pending_jobs' => $this->pendingJobsCount(),
            'failed_jobs' => $this->failedJobsCount(),
            'running_jobs' => $running->count(),
            'stale_jobs' => $stale->count(),
            'latest_shopify_import' => SyncJob::query()
                ->where('type', 'import')
                ->where('source', 'shopify')
                ->latest('id')
                ->first(),
            'latest_varle_export' => SyncJob::query()
                ->where('type', 'export')
                ->where('channel', 'varle')
                ->latest('id')
                ->first(),
            'latest_daily_sync' => SyncJob::query()
                ->where('type', 'daily_sync')
                ->latest('id')
                ->first(),
            'worker_hint' => 'php artisan queue:work database --sleep=3 --tries=1 --timeout=7200 --memory=512',
        ];
    }

    private function pendingJobsCount(): ?int
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        return (int) DB::table('jobs')->count();
    }

    private function failedJobsCount(): ?int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        return (int) DB::table('failed_jobs')->count();
    }
}
