<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\Filament\Resources\SyncJobs\Actions\CheckStuckJobsNowAction;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSyncJobs extends ListRecords
{
    protected static string $resource = SyncJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CheckStuckJobsNowAction::make(),
            CreateAction::make(),
        ];
    }
}
