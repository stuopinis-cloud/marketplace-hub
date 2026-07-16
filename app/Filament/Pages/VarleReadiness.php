<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ShopifyImportHistoryWidget;
use App\Filament\Widgets\ShopifyImportStatusWidget;
use App\Filament\Pages\VarleReadiness\Widgets\LatestVarleExportWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleDataQualityStatsWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleExportApprovalStatsWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleReadinessBreakdownWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleReadinessRefreshStatusWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleReadinessSummaryWidget;
use App\Filament\Pages\VarleReadiness\Widgets\VarleRecentProblemsWidget;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use App\Services\Sync\JobDispatchResult;
use App\Services\Sync\MarketplaceJobDispatcher;
use App\Services\Sync\SyncJobFailedCsvExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    $this->notifyDispatchResult(
                        $dispatcher->dispatchShopifyImport(),
                        'Shopify import queued',
                    );
                }),
            Action::make('runVarleExport')
                ->label('Run Varle export')
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    $this->notifyDispatchResult(
                        $dispatcher->dispatchVarleExport(),
                        'Varle export queued',
                    );
                }),
            Action::make('runDailySync')
                ->label('Run daily sync')
                ->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    $this->notifyDispatchResult(
                        $dispatcher->dispatchDailySync(),
                        'Daily marketplace sync queued',
                    );
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
            Action::make('refreshReadiness')
                ->label('Refresh readiness cache')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    $this->notifyDispatchResult(
                        $dispatcher->dispatchReadinessRefresh(),
                        'Varle readiness refresh queued',
                    );
                }),
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
            ShopifyImportStatusWidget::class,
            VarleReadinessRefreshStatusWidget::class,
            LatestVarleExportWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            ShopifyImportHistoryWidget::class,
            VarleReadinessSummaryWidget::class,
            VarleExportApprovalStatsWidget::class,
            VarleDataQualityStatsWidget::class,
            VarleReadinessBreakdownWidget::class,
            VarleRecentProblemsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    private function notifyDispatchResult(JobDispatchResult $result, string $queuedTitle): void
    {
        if ($result->alreadyRunning) {
            Notification::make()
                ->title('Job already running')
                ->body($result->message ?? 'Another job is already running.')
                ->warning()
                ->send();

            return;
        }

        $body = $result->message ?? 'Job started in background.';

        if ($result->syncJob !== null) {
            $body .= ' Sync job #'.$result->syncJob->id.'.';
        }

        Notification::make()
            ->title($queuedTitle)
            ->body($body)
            ->success()
            ->send();
    }
}
