<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

class SupplierCredentialResolver
{
    public function resolveBearerToken(Supplier $supplier): ?string
    {
        if ($supplier->auth_type !== Supplier::AUTH_BEARER_TOKEN) {
            return null;
        }

        $fromCredentials = data_get($supplier->credentials, 'token');

        if (filled($fromCredentials)) {
            return (string) $fromCredentials;
        }

        $fromEnv = config('services.entirem.token');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    public function hasBearerToken(Supplier $supplier): bool
    {
        return filled($this->resolveBearerToken($supplier));
    }
}
