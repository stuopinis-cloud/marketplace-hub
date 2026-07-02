<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VarleDataQualityStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Data quality';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $quality = app(VarleReadinessMetrics::class)->dataQuality();

        return [
            Stat::make('Published products', (string) $quality['published_products'])
                ->color('success'),
            Stat::make('Unpublished products', (string) $quality['unpublished_products'])
                ->color($quality['unpublished_products'] > 0 ? 'gray' : 'success'),
            Stat::make('Total variants', (string) $quality['total_variants']),
            Stat::make('Missing barcode', (string) $quality['variants_missing_barcode'])
                ->color($quality['variants_missing_barcode'] > 0 ? 'danger' : 'success'),
            Stat::make('Missing SKU', (string) $quality['variants_missing_sku'])
                ->color($quality['variants_missing_sku'] > 0 ? 'danger' : 'success'),
            Stat::make('Price <= 0', (string) $quality['variants_with_invalid_price'])
                ->color($quality['variants_with_invalid_price'] > 0 ? 'warning' : 'success'),
            Stat::make('Missing images', (string) $quality['products_missing_images'])
                ->color($quality['products_missing_images'] > 0 ? 'warning' : 'success'),
            Stat::make('Missing category', (string) $quality['products_missing_category_mapping'])
                ->color($quality['products_missing_category_mapping'] > 0 ? 'danger' : 'success'),
            Stat::make('Fallback category', (string) $quality['products_using_fallback_category'])
                ->color($quality['products_using_fallback_category'] > 0 ? 'warning' : 'success'),
        ];
    }
}
