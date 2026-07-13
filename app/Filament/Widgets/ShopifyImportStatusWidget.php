<?php

namespace App\Filament\Widgets;

use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use App\Services\Sync\SyncJobHealthService;
use Filament\Widgets\Widget;

class ShopifyImportStatusWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.shopify-import-status';

    public function getImport(): ?SyncJob
    {
        return app(VarleReadinessMetrics::class)->latestShopifyImport();
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        $import = $this->getImport();

        if ($import === null) {
            return [];
        }

        return app(SyncJobHealthService::class)->assess($import);
    }

    public function getSummaryMessage(): string
    {
        $import = $this->getImport();
        $health = $this->getHealth();

        if ($import === null || $health === []) {
            return '';
        }

        return match ($health['health_status'] ?? null) {
            SyncJobHealthService::HEALTH_COMPLETED => 'Last Shopify import completed successfully.',
            SyncJobHealthService::HEALTH_FAILED => 'Shopify import failed: '.($import->error_message ?: 'Unknown error.'),
            SyncJobHealthService::HEALTH_STUCK => $health['human_message']
                ? 'Shopify import appears stuck. '.preg_replace('/^Import appears stuck\. /', '', $health['human_message'])
                : 'Shopify import appears stuck.',
            default => $health['human_message'] ?? '',
        };
    }

    public function getProductsImported(): ?int
    {
        $import = $this->getImport();

        if ($import === null) {
            return null;
        }

        $fromContext = data_get($import->context, 'products_imported');

        return $fromContext !== null ? (int) $fromContext : (int) $import->success_items;
    }

    public function getVariantsImported(): ?int
    {
        $import = $this->getImport();

        if ($import === null) {
            return null;
        }

        $fromContext = data_get($import->context, 'variants_imported');

        return $fromContext !== null ? (int) $fromContext : null;
    }

    public function getDuration(): ?string
    {
        $import = $this->getImport();

        if ($import === null) {
            return null;
        }

        return app(SyncJobHealthService::class)->durationLabel($import);
    }

    public function getHeartbeatAge(): string
    {
        $import = $this->getImport();

        if ($import === null) {
            return '—';
        }

        return app(SyncJobHealthService::class)->heartbeatAgeLabel($import);
    }
}
