<?php

namespace App\Filament\Resources\FeedFiles\Pages;

use App\Filament\Resources\FeedFiles\FeedFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeedFiles extends ListRecords
{
    protected static string $resource = FeedFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
