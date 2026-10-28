<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPiguSupplierXml extends ListRecords
{
    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Pigu tiekėjų XML';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Pridėti tiekėją'),
        ];
    }
}
