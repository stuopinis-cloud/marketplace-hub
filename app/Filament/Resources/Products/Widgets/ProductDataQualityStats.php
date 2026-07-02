<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductDataQualityStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $missingBarcodeCount = ProductVariant::query()
            ->where(fn ($query) => $query->whereNull('barcode')->orWhere('barcode', ''))
            ->count();

        return [
            Stat::make('Missing barcode variants', (string) $missingBarcodeCount)
                ->description('Variants without barcode cannot be exported to Varle')
                ->color($missingBarcodeCount > 0 ? 'warning' : 'success'),
        ];
    }
}
