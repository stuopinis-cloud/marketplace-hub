<?php

namespace App\Filament\Widgets;

use App\Services\Sync\MarketplaceJobHealthService;
use App\Services\Sync\SyncJobHealthService;
use Filament\Widgets\Widget;

class MarketplaceJobHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.marketplace-job-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -20;

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return app(MarketplaceJobHealthService::class)->snapshot();
    }

    public function getShopifyLabel(): string
    {
        return $this->jobLabel($this->getSnapshot()['latest_shopify_import'] ?? null);
    }

    public function getVarleLabel(): string
    {
        return $this->jobLabel($this->getSnapshot()['latest_varle_export'] ?? null);
    }

    public function getDailyLabel(): string
    {
        return $this->jobLabel($this->getSnapshot()['latest_daily_sync'] ?? null);
    }

    private function jobLabel(mixed $job): string
    {
        if ($job === null) {
            return 'None yet';
        }

        $assessed = app(SyncJobHealthService::class)->assess($job);

        return sprintf(
            '#%d · %s · %s',
            $job->id,
            $assessed['label'],
            $job->finished_at?->diffForHumans() ?? $job->started_at?->diffForHumans() ?? '—',
        );
    }
}
