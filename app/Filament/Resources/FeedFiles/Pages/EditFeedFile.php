<?php

namespace App\Filament\Resources\FeedFiles\Pages;

use App\Filament\Resources\FeedFiles\FeedFileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeedFile extends EditRecord
{
    protected static string $resource = FeedFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
