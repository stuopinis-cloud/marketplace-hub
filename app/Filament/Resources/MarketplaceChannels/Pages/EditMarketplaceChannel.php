<?php

namespace App\Filament\Resources\MarketplaceChannels\Pages;

use App\Filament\Resources\MarketplaceChannels\MarketplaceChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceChannel extends EditRecord
{
    protected static string $resource = MarketplaceChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
