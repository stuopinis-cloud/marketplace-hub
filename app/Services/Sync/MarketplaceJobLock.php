<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class MarketplaceJobLock
{
    public const string SHOPIFY_IMPORT = 'shopify-import';

    public const string VARLE_READINESS_REFRESH = 'varle-readiness-refresh';

    public const string VARLE_EXPORT = 'varle-export';

    public const string MARKETPLACE_DAILY_SYNC = 'marketplace-daily-sync';

    public const string SUPPLIER_SYNC_PREFIX = 'supplier-sync:';

    public const int DEFAULT_SECONDS = 7200;

    public static function secondsFor(string $key): int
    {
        return match ($key) {
            self::SHOPIFY_IMPORT => 3600,
            self::VARLE_EXPORT => 1800,
            self::VARLE_READINESS_REFRESH => 1800,
            self::MARKETPLACE_DAILY_SYNC => 7200,
            default => self::DEFAULT_SECONDS,
        };
    }

    public static function forSupplier(string $supplierCode): string
    {
        return self::SUPPLIER_SYNC_PREFIX.mb_strtolower($supplierCode);
    }

    public static function make(string $key): Lock
    {
        return Cache::lock($key, self::secondsFor($key));
    }

    public static function isLocked(string $key): bool
    {
        $lock = self::make($key);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    public static function forceRelease(string $key): void
    {
        try {
            self::make($key)->forceRelease();
        } catch (\Throwable) {
            // Best-effort cleanup for stale locks.
        }
    }

    public static function keyForSyncJob(SyncJob $job): ?string
    {
        return match (true) {
            $job->type === 'import' && $job->source === 'shopify' => self::SHOPIFY_IMPORT,
            $job->type === 'export' && $job->channel === 'varle' => self::VARLE_EXPORT,
            $job->type === 'readiness' => self::VARLE_READINESS_REFRESH,
            $job->type === 'daily_sync' => self::MARKETPLACE_DAILY_SYNC,
            $job->type === 'import' && filled($job->source) && $job->source !== 'shopify' => self::forSupplier((string) $job->source),
            default => null,
        };
    }

    public static function hasActiveJob(string $type, ?string $source = null, ?string $channel = null): bool
    {
        $query = SyncJob::query()
            ->where('type', $type)
            ->whereIn('status', [SyncJobStatus::Pending, SyncJobStatus::Running]);

        if ($source !== null) {
            $query->where('source', $source);
        }

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        return $query->exists();
    }
}
