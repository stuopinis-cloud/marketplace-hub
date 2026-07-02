<?php

namespace App\Filament\Pages;

use App\Filament\Pages\VarleReadiness\Widgets\LatestShopifyImportWidget;
use App\Filament\Pages\VarleReadiness\Widgets\LatestVarleExportWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleDataQualityStatsWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleExportApprovalStatsWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleRecentProblemsWidget;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use App\Services\Sync\SyncJobFailedCsvExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

class VarleReadiness extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Varle Readiness';

    protected static ?string $title = 'Varle Readiness';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 0;

    protected function getHeaderActions(): array
    {
        $metrics = app(VarleReadinessMetrics::class);
        $latestExport = $metrics->latestVarleExport();

        return [
            Action::make('runShopifyImport')
                ->label('Run Shopify import')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->requiresConfirmation()
                ->action(function (): void {
                    Artisan::call('shopify:import-products');

                    Notification::make()
                        ->title('Shopify import completed')
                        ->body(trim(Artisan::output()) ?: 'Import finished.')
                        ->success()
                        ->send();
                }),
            Action::make('runVarleExport')
                ->label('Run Varle export')
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->requiresConfirmation()
                ->action(function (): void {
                    Artisan::call('varle:export-xml');

                    Notification::make()
                        ->title('Varle export completed')
                        ->body(trim(Artisan::output()) ?: 'Export finished.')
                        ->success()
                        ->send();
                }),
            Action::make('downloadFailedCsv')
                ->label('Download latest failed CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $latestExport !== null)
                ->action(function () use ($latestExport): mixed {
                    if ($latestExport === null) {
                        return null;
                    }

                    return app(SyncJobFailedCsvExporter::class)->downloadResponse($latestExport);
                }),
            Action::make('openXmlFeed')
                ->label('Open XML feed')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): string => $metrics->publicFeedUrl($latestExport))
                ->openUrlInNewTab(),
            Action::make('reviewPendingProducts')
                ->label('Review pending products')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->url(ProductResource::getUrl('index', [
                    'tableFilters' => [
                        'varle_export_status' => [
                            'value' => 'pending_review',
                        ],
                    ],
                ])),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LatestShopifyImportWidget::class,
            LatestVarleExportWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            VarleExportApprovalStatsWidget::class,
            VarleDataQualityStatsWidget::class,
            VarleRecentProblemsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
