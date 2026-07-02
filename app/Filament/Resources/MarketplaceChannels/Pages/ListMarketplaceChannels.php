<?php

namespace App\Filament\Resources\MarketplaceChannels\Pages;

use App\Filament\Resources\MarketplaceChannels\MarketplaceChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceChannels extends ListRecords
{
    protected static string $resource = MarketplaceChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
