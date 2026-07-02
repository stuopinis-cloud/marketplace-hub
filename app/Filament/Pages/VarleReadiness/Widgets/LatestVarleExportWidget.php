<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\Widget;

class LatestVarleExportWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.varle-readiness.latest-varle-export';

    public function getExport(): ?SyncJob
    {
        return app(VarleReadinessMetrics::class)->latestVarleExport();
    }

    public function getMetrics(): VarleReadinessMetrics
    {
        return app(VarleReadinessMetrics::class);
    }
}
