<?php

namespace App\Filament\Resources\Suppliers\Actions;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvSupplierImporter;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class PreviewSupplierCsvAction
{
    public static function make(): Action
    {
        return Action::make('previewSupplierCsv')
            ->label('Preview CSV')
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (Supplier $record): bool => in_array($record->connector_type, [
                Supplier::CONNECTOR_CSV_URL,
                Supplier::CONNECTOR_CSV_UPLOAD,
            ], true))
            ->modalHeading('CSV feed preview')
            ->modalWidth('5xl')
            ->modalContent(function (Supplier $record): View {
                return view('filament.resources.suppliers.csv-preview', self::buildPreviewData($record));
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
            $preview = app(SupplierCsvSupplierImporter::class)->preview($record);

            return [
                'error' => null,
                'headers' => $preview['headers'],
                'preview_rows' => $preview['preview_rows'],
                'sample_mappings' => $preview['sample_mappings'],
            ];
        } catch (\Throwable $exception) {
            return [
                'error' => $exception->getMessage(),
                'headers' => [],
                'preview_rows' => [],
                'sample_mappings' => [],
            ];
        }
    }
}
