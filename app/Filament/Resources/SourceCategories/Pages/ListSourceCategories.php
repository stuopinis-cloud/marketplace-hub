<?php

namespace App\Filament\Resources\SourceCategories\Pages;

use App\Filament\Pages\BulkCategoryApproval;
use App\Filament\Resources\SourceCategories\SourceCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSourceCategories extends ListRecords
{
    protected static string $resource = SourceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openBulkApproval')
                ->label('Bulk category approval')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->url(BulkCategoryApproval::getUrl()),
        ];
    }
}
