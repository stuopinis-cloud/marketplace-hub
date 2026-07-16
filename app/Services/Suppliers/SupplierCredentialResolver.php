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

    /**
     * @return array{username: string, password: string}|null
     */
    public function resolveUsernamePassword(Supplier $supplier): ?array
    {
        if (! in_array($supplier->auth_type, [Supplier::AUTH_BASIC, Supplier::AUTH_NTLM], true)) {
            return null;
        }

        $username = data_get($supplier->credentials, 'username');
        $password = data_get($supplier->credentials, 'password');

        if (filled($username) && filled($password)) {
            return [
                'username' => (string) $username,
                'password' => (string) $password,
            ];
        }

        return $this->resolveEnvUsernamePassword($supplier);
    }

    public function hasUsernamePassword(Supplier $supplier): bool
    {
        return $this->resolveUsernamePassword($supplier) !== null;
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function resolveEnvUsernamePassword(Supplier $supplier): ?array
    {
        if ($supplier->code === Supplier::CODE_PREZIOSO || $supplier->auth_type === Supplier::AUTH_NTLM) {
            $username = config('services.prezioso.ntlm_username');
            $password = config('services.prezioso.ntlm_password');

            if (filled($username) && filled($password)) {
                return [
                    'username' => (string) $username,
                    'password' => (string) $password,
                ];
            }
        }

        return null;
    }
}
