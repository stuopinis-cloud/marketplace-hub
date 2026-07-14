<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use App\Services\Sync\SyncJobHealthService;
use Filament\Widgets\Widget;

class VarleReadinessRefreshStatusWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.varle-readiness-refresh-status';

    public function getRefreshJob(): ?SyncJob
    {
        return app(VarleReadinessMetrics::class)->latestReadinessRefresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        $job = $this->getRefreshJob();

        if ($job === null) {
            return [];
        }

        return app(SyncJobHealthService::class)->assess($job);
    }

    public function getSummaryMessage(): string
    {
        $job = $this->getRefreshJob();
        $health = $this->getHealth();

        if ($job === null || $health === []) {
            return '';
        }

        return match ($health['health_status'] ?? null) {
            SyncJobHealthService::HEALTH_COMPLETED => 'Last readiness refresh completed successfully.',
            SyncJobHealthService::HEALTH_FAILED => 'Readiness refresh failed: '.($job->error_message ?: 'Unknown error.'),
            SyncJobHealthService::HEALTH_STUCK => $health['human_message']
                ? 'Readiness refresh appears stuck. '.preg_replace('/^Import appears stuck\. /', '', $health['human_message'])
                : 'Readiness refresh appears stuck.',
            default => $job->status->value === 'pending'
                ? 'Readiness refresh is queued.'
                : ($health['human_message'] ?? 'Readiness refresh is running.'),
        };
    }

    public function getDuration(): ?string
    {
        $job = $this->getRefreshJob();

        if ($job === null) {
            return null;
        }

        return app(SyncJobHealthService::class)->durationLabel($job);
    }

    public function getHeartbeatAge(): string
    {
        $job = $this->getRefreshJob();

        if ($job === null) {
            return '—';
        }

        return app(SyncJobHealthService::class)->heartbeatAgeLabel($job);
    }
}
