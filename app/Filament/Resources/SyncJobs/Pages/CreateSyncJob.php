<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\Filament\Resources\SyncJobs\SyncJobResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSyncJob extends CreateRecord
{
    protected static string $resource = SyncJobResource::class;
}
