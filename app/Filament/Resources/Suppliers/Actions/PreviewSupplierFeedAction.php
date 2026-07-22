<?php

namespace App\Filament\Resources\Suppliers\Actions;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierFeedPreviewService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * Feed preview action for all connector types (CSV, XML, JSON/API, M-Tac, Helikon),
 * backed by {@see SupplierFeedPreviewService}. Replaces the CSV-only preview action.
 */
class PreviewSupplierFeedAction
{
    public static function make(): Action
    {
        return Action::make('previewSupplierFeed')
            ->label('Preview feed')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading('Feed preview')
            ->modalWidth('5xl')
            ->modalContent(function (Supplier $record): View {
                return view('filament.resources.suppliers.feed-preview', self::buildPreviewData($record));
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPreviewData(Supplier $record): array
    {
        try {
            return app(SupplierFeedPreviewService::class)->preview($record);
        } catch (Throwable $exception) {
            return [
                'error' => $exception->getMessage(),
                'type' => $record->connector_type,
            ];
        }
    }
}
