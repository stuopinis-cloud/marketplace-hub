<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Models\Product;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductVarleReadinessStats extends StatsOverviewWidget
{
    protected ?string $heading = 'Varle readiness overview';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $metrics = app(VarleReadinessMetrics::class);
        $summary = $metrics->readinessSummary();
        $totalProducts = Product::query()->count();
        $staleSupplierStock = Product::query()
            ->whereJsonContains('varle_issue_codes', 'supplier_stock_stale')
            ->count();
        $noStock = Product::query()
            ->where(function ($query): void {
                $query->where('varle_stock_status', 'no_exportable_stock')
                    ->orWhereJsonContains('varle_issue_codes', 'no_stock_anywhere')
                    ->orWhereJsonContains('varle_issue_codes', 'no_exportable_variants');
            })
            ->count();

        return [
            Stat::make('Total products', (string) $totalProducts)
                ->color('gray')
                ->url($metrics->productsFilterUrl([])),
            Stat::make('Ready', (string) $summary['ready_for_export'])
                ->color('success')
                ->url($metrics->productsFilterUrl(['ready_for_varle' => ['isActive' => true]])),
            Stat::make('Not ready', (string) max(0, $totalProducts - $summary['ready_for_export']))
                ->color($totalProducts - $summary['ready_for_export'] > 0 ? 'danger' : 'success')
                ->url($metrics->productsFilterUrl(['not_ready_for_varle' => ['isActive' => true]])),
            Stat::make('Missing barcode', (string) $summary['missing_barcode'])
                ->color($summary['missing_barcode'] > 0 ? 'danger' : 'success')
                ->url($metrics->productsFilterUrl(['missing_barcode_issue' => ['isActive' => true]])),
            Stat::make('Missing images', (string) ($summary['missing_variant_images'] + $summary['no_images']))
                ->color(($summary['missing_variant_images'] + $summary['no_images']) > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['missing_image_issue' => ['isActive' => true]])),
            Stat::make('Missing category', (string) $summary['missing_category_mapping'])
                ->color($summary['missing_category_mapping'] > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['varle_category_status' => ['value' => 'missing']])),
            Stat::make('No stock', (string) $noStock)
                ->color($noStock > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['no_exportable_stock' => ['isActive' => true]])),
            Stat::make('Stale supplier stock', (string) $staleSupplierStock)
                ->color($staleSupplierStock > 0 ? 'warning' : 'success')
                ->url($metrics->productsFilterUrl(['supplier_stock_stale' => ['isActive' => true]])),
        ];
    }
}
