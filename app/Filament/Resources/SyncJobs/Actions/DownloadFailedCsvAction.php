<?php

namespace App\Filament\Resources\SyncJobs\Actions;

use App\Models\SyncJob;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class DownloadFailedCsvAction
{
    public static function make(): Action
    {
        return Action::make('downloadFailedCsv')
            ->label('Download failed CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (SyncJob $record): bool => $record->type === 'export')
            ->action(function (SyncJob $record, SyncJobFailedCsvExporter $exporter) {
                return $exporter->downloadResponse($record);
            });
    }
}
