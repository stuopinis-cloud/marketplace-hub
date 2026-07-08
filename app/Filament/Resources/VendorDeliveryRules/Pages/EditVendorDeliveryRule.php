<?php

namespace App\Filament\Resources\VendorDeliveryRules\Pages;

use App\Filament\Resources\VendorDeliveryRules\VendorDeliveryRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendorDeliveryRule extends EditRecord
{
    protected static string $resource = VendorDeliveryRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
