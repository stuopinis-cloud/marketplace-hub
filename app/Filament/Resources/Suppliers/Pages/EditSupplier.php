<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $token = $data['credential_token'] ?? null;

        if (filled($token) && $token !== '********') {
            $data['credentials'] = array_merge($this->record->credentials ?? [], [
                'token' => $token,
            ]);
        }

        unset($data['credential_token']);

        return $data;
    }
}
