<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\Widget;

class VarleReadinessBreakdownWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected string $view = 'filament.pages.varle-readiness.breakdown-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = app(VarleReadinessMetrics::class);

        return [
            'byVendor' => $metrics->breakdownByVendor(),
            'byProductType' => $metrics->breakdownByProductType(),
            'byIssueCode' => $metrics->breakdownByIssueCode(),
            'byBarcodeStatus' => $metrics->breakdownByBarcodeStatus(),
            'byStockStatus' => $metrics->breakdownByStockStatus(),
            'byCategoryStatus' => $metrics->breakdownByCategoryStatus(),
        ];
    }
}
