<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VarleExportApprovalStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Varle export approval';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $stats = app(VarleReadinessMetrics::class)->exportControlStats();

        return [
            Stat::make('Pending review', (string) $stats['pending_review_products'])
                ->description('Open pending products')
                ->color($stats['pending_review_products'] > 0 ? 'warning' : 'success')
                ->url(ProductResource::getUrl('index', [
                    'tableFilters' => [
                        'varle_export_status' => [
                            'value' => 'pending_review',
                        ],
                    ],
                ])),
            Stat::make('Forced include', (string) $stats['forced_include_products'])
                ->color($stats['forced_include_products'] > 0 ? 'info' : 'gray'),
            Stat::make('Excluded', (string) $stats['excluded_products'])
                ->color($stats['excluded_products'] > 0 ? 'danger' : 'success'),
            Stat::make('Disabled category mappings', (string) $stats['disabled_category_mappings'])
                ->color($stats['disabled_category_mappings'] > 0 ? 'warning' : 'success')
                ->url(route('filament.admin.resources.category-mappings.index', [
                    'tableFilters' => [
                        'export_enabled' => [
                            'value' => '0',
                        ],
                    ],
                ])),
            Stat::make('New from latest import', (string) $stats['new_products_from_latest_import'])
                ->description('Shopify products awaiting first review')
                ->color($stats['new_products_from_latest_import'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
