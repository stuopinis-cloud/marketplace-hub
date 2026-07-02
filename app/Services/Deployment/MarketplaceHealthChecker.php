<?php

namespace App\Services\Deployment;

use App\Models\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MarketplaceHealthChecker
{
    public function __construct(
        private readonly MarketplaceStorageBootstrap $storageBootstrap,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     app: string,
     *     time: string,
     *     database: string,
     * }
     */
    public function publicStatus(): array
    {
        $database = $this->databaseStatus();

        return [
            'status' => $database === 'ok' ? 'ok' : 'error',
            'app' => 'Marketplace Hub',
            'time' => now()->toIso8601String(),
            'database' => $database,
        ];
    }

    public function isHealthy(): bool
    {
        return $this->publicStatus()['status'] === 'ok';
    }

    /**
     * @return array<string, mixed>
     */
    public function detailedReport(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'storage_writable' => $this->storageWritableCheck(),
            'feed' => $this->feedCheck(),
            'shopify' => $this->shopifyCheck(),
            'sync_jobs' => $this->syncJobsSummary(),
        ];

        $failed = collect($checks)
            ->except('sync_jobs')
            ->contains(fn (array $check): bool => ($check['status'] ?? '') !== 'ok');

        return [
            'status' => $failed ? 'error' : 'ok',
            'app' => 'Marketplace Hub',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function databaseStatus(): string
    {
        return $this->databaseCheck()['status'];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'status' => 'ok',
                'message' => 'Database connection successful.',
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, message: string, paths: array<int, string>}
     */
    private function storageWritableCheck(): array
    {
        $paths = [
            storage_path(),
            storage_path('app'),
            storage_path('app/public'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        $paths = array_merge($paths, $this->storageBootstrap->requiredDirectories());

        foreach ($paths as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
                return [
                    'status' => 'error',
                    'message' => "Directory does not exist: {$path}",
                    'paths' => $paths,
                ];
            }

            if (! is_writable($path)) {
                return [
                    'status' => 'error',
                    'message' => "Directory is not writable: {$path}",
                    'paths' => $paths,
                ];
            }
        }

        return [
            'status' => 'ok',
            'message' => 'Storage and cache directories are writable.',
            'paths' => $paths,
        ];
    }

    /**
     * @return array{status: string, message: string, path: string, exists: bool}
     */
    private function feedCheck(): array
    {
        $relativePath = (string) config('marketplace.exports.varle.feed_path', 'feeds/varle.xml');
        $directory = dirname($relativePath);
        $exists = Storage::disk('public')->exists($relativePath);
        $directoryWritable = is_writable(storage_path('app/public/'.($directory === '.' ? '' : $directory)));

        if ($exists) {
            return [
                'status' => 'ok',
                'message' => 'Public Varle feed file exists.',
                'path' => $relativePath,
                'exists' => true,
            ];
        }

        if ($directoryWritable || is_writable(storage_path('app/public'))) {
            return [
                'status' => 'ok',
                'message' => 'Feed file not generated yet, but feed directory is writable.',
                'path' => $relativePath,
                'exists' => false,
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Feed file is missing and feed directory is not writable.',
            'path' => $relativePath,
            'exists' => false,
        ];
    }

    /**
     * @return array{status: string, message: string, shop: ?string, client_id_configured: bool, client_secret_configured: bool}
     */
    private function shopifyCheck(): array
    {
        $shop = config('shopify.shop');
        $clientId = config('shopify.client_id');
        $clientSecret = config('shopify.client_secret');

        $configured = filled($shop) && filled($clientId) && filled($clientSecret);

        return [
            'status' => $configured ? 'ok' : 'error',
            'message' => $configured
                ? 'Shopify credentials are configured.'
                : 'Shopify credentials are incomplete. Set SHOPIFY_SHOP, SHOPIFY_CLIENT_ID, and SHOPIFY_CLIENT_SECRET.',
            'shop' => filled($shop) ? (string) $shop : null,
            'client_id_configured' => filled($clientId),
            'client_secret_configured' => filled($clientSecret),
        ];
    }

    /**
     * @return array{status: string, latest_shopify_import: ?array<string, mixed>, latest_varle_export: ?array<string, mixed>}
     */
    private function syncJobsSummary(): array
    {
        $latestImport = SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->latest('id')
            ->first();

        $latestExport = SyncJob::query()
            ->where('type', 'export')
            ->where('channel', 'varle')
            ->latest('id')
            ->first();

        return [
            'status' => 'ok',
            'latest_shopify_import' => $latestImport ? [
                'id' => $latestImport->id,
                'status' => $latestImport->status?->value ?? (string) $latestImport->status,
                'started_at' => $latestImport->started_at?->toIso8601String(),
                'finished_at' => $latestImport->finished_at?->toIso8601String(),
                'success_items' => $latestImport->success_items,
                'failed_items' => $latestImport->failed_items,
            ] : null,
            'latest_varle_export' => $latestExport ? [
                'id' => $latestExport->id,
                'status' => $latestExport->status?->value ?? (string) $latestExport->status,
                'started_at' => $latestExport->started_at?->toIso8601String(),
                'finished_at' => $latestExport->finished_at?->toIso8601String(),
                'success_items' => $latestExport->success_items,
                'failed_items' => $latestExport->failed_items,
            ] : null,
        ];
    }
}
