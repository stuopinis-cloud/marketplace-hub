<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Str;

class SupplierFormDataFactory
{
    /**
     * Build a non-persisted Supplier from Filament form state (for preview/dry-run).
     *
     * @param  array<string, mixed>  $data
     */
    public function makeTransient(array $data, ?Supplier $existing = null): Supplier
    {
        $supplier = $existing ? $existing->replicate() : new Supplier;
        $supplier->exists = $existing?->exists ?? false;
        if ($existing !== null) {
            $supplier->id = $existing->id;
        }

        $config = array_merge(
            $existing?->config ?? [],
            is_array($data['config'] ?? null) ? $data['config'] : [],
        );

        if (isset($config['request_headers_json']) && is_string($config['request_headers_json']) && filled($config['request_headers_json'])) {
            $decoded = json_decode($config['request_headers_json'], true);
            if (is_array($decoded)) {
                $config['request_headers'] = $decoded;
            }
            unset($config['request_headers_json']);
        }

        if (isset($config['request_body_json']) && is_string($config['request_body_json'])) {
            $decoded = json_decode($config['request_body_json'], true);
            if (is_array($decoded)) {
                $config['request_body'] = $decoded;
            }
            unset($config['request_body_json']);
        }

        $credentials = $existing?->credentials ?? [];
        $token = $data['credential_token'] ?? null;
        if (filled($token) && $token !== '********') {
            $credentials['token'] = $token;
        }
        $username = $data['credential_username'] ?? null;
        if (filled($username)) {
            $credentials['username'] = $username;
        }
        $password = $data['credential_password'] ?? null;
        if (filled($password) && $password !== '********') {
            $credentials['password'] = $password;
        }

        $supplier->fill([
            'name' => $data['name'] ?? $existing?->name,
            'code' => $data['code'] ?? $existing?->code,
            'enabled' => (bool) ($data['enabled'] ?? $existing?->enabled ?? true),
            'sync_enabled' => (bool) ($data['sync_enabled'] ?? $existing?->sync_enabled ?? false),
            'connector_type' => $data['connector_type'] ?? $existing?->connector_type,
            'endpoint_url' => $data['endpoint_url'] ?? $existing?->endpoint_url,
            'auth_type' => $data['auth_type'] ?? $existing?->auth_type ?? Supplier::AUTH_NONE,
            'credentials' => $credentials,
            'config' => $config,
            'sync_interval_minutes' => $data['sync_interval_minutes'] ?? $existing?->sync_interval_minutes,
            'force_daily_sync' => (bool) ($data['force_daily_sync'] ?? $existing?->force_daily_sync ?? false),
            'stale_after_minutes' => $data['stale_after_minutes'] ?? $existing?->stale_after_minutes,
            'availability_fallback_quantity' => $data['availability_fallback_quantity']
                ?? $existing?->availability_fallback_quantity
                ?? 5,
        ]);

        return $supplier;
    }

    public static function codeFromName(string $name): string
    {
        $code = Str::of($name)->lower()->ascii()->slug('_')->limit(40, '')->toString();

        return $code !== '' ? $code : 'supplier';
    }
}
