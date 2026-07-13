<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VarleReadinessSummaryWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Export readiness';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $metrics = app(VarleReadinessMetrics::class);
        $summary = $metrics->readinessSummary();

        return [
            Stat::make('Ready for export', (string) $summary['ready_for_export'])
                ->color('success')
                ->url($metrics->productsFilterUrl(['ready_for_varle' => ['isActive' => true]])),
            Stat::make('Pending review', (string) $summary['pending_review'])
                ->color($summary['pending_review'] > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['varle_export_status' => ['value' => 'pending_review']])),
            Stat::make('Forced include', (string) $summary['forced_include'])
                ->url($metrics->productsFilterUrl(['varle_export_status' => ['value' => 'include']])),
            Stat::make('Excluded', (string) $summary['excluded'])
                ->color($summary['excluded'] > 0 ? 'gray' : 'success')
                ->url($metrics->productsFilterUrl(['varle_export_status' => ['value' => 'exclude']])),
            Stat::make('Missing barcode', (string) $summary['missing_barcode'])
                ->color($summary['missing_barcode'] > 0 ? 'danger' : 'success')
                ->url($metrics->productsFilterUrl(['varle_barcode_status' => ['value' => 'some_variants_missing_barcode']])),
            Stat::make('Missing category', (string) $summary['missing_category_mapping'])
                ->color($summary['missing_category_mapping'] > 0 ? 'danger' : 'success')
                ->url($metrics->productsFilterUrl(['varle_category_status' => ['value' => 'missing']])),
            Stat::make('Missing variant images', (string) $summary['missing_variant_images'])
                ->color($summary['missing_variant_images'] > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['varle_image_status' => ['value' => 'some_exportable_variants_missing_image']])),
            Stat::make('No images', (string) $summary['no_images'])
                ->color($summary['no_images'] > 0 ? 'danger' : 'success')
                ->url($metrics->productsFilterUrl(['varle_image_status' => ['value' => 'no_images']])),
            Stat::make('Out of stock blocked', (string) $summary['out_of_stock_no_backorder'])
                ->color($summary['out_of_stock_no_backorder'] > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['varle_stock_status' => ['value' => 'out_of_stock_blocked']])),
            Stat::make('Backorder exportable', (string) $summary['backorder_exportable'])
                ->url($metrics->productsFilterUrl(['varle_stock_status' => ['value' => 'mixed_stock_backorder']])),
            Stat::make('Default delivery rule', (string) $summary['vendor_delivery_missing'])
                ->url($metrics->productsFilterUrl(['varle_vendor_delivery_rule_status' => ['value' => 'default_rule_used']])),
            Stat::make('Vendor disabled', (string) $summary['vendor_disabled'])
                ->color($summary['vendor_disabled'] > 0 ? 'danger' : 'success'),
            Stat::make('Products with warnings', (string) $summary['products_with_warnings'])
                ->color($summary['products_with_warnings'] > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['has_issues' => ['isActive' => true]])),
        ];
    }
}
