<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\Filament\Resources\SyncJobs\Actions\CancelSyncJobAction;
use App\Filament\Resources\SyncJobs\Actions\DownloadFailedCsvAction;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSyncJob extends EditRecord
{
    protected static string $resource = SyncJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CancelSyncJobAction::make(),
            DownloadFailedCsvAction::make(),
            DeleteAction::make(),
        ];
    }
}
