<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\Widget;

class LatestShopifyImportWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.varle-readiness.latest-shopify-import';

    public function getImport(): ?SyncJob
    {
        return app(VarleReadinessMetrics::class)->latestShopifyImport();
    }
}
