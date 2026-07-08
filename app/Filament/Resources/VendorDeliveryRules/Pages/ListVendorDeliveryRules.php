<?php

namespace App\Filament\Resources\VendorDeliveryRules\Pages;

use App\Filament\Resources\VendorDeliveryRules\VendorDeliveryRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorDeliveryRules extends ListRecords
{
    protected static string $resource = VendorDeliveryRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
