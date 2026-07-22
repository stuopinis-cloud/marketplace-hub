<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

class SupplierProvisioner
{
    /**
     * Create supplier if missing. When it already exists, preserve dashboard-managed fields
     * and only fill blanks / merge config without wiping mappings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function ensureSupplier(string $code, array $attributes): Supplier
    {
        $existing = Supplier::query()->where('code', $code)->first();

        if ($existing === null) {
            return Supplier::query()->create(array_merge(['code' => $code], $attributes));
        }

        $updates = [];

        foreach ($attributes as $key => $value) {
            if ($key === 'config') {
                continue;
            }

            $current = $existing->{$key} ?? null;

            if ($current === null || $current === '') {
                $updates[$key] = $value;
            }
        }

        if (isset($attributes['config']) && is_array($attributes['config'])) {
            // Existing config wins on conflict so dashboard mappings are preserved.
            $updates['config'] = array_merge($attributes['config'], $existing->config ?? []);
        }

        if ($updates !== []) {
            $existing->update($updates);
        }

        return $existing->refresh();
    }

    public function ensureMtacSupplier(): Supplier
    {
        return $this->ensureSupplier(Supplier::CODE_MTAC, [
            'name' => 'M-Tac',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_MTAC,
            'endpoint_url' => 'https://m-tac.pl/xml?id=42',
            'auth_type' => 'none',
            'stock_priority' => 100,
            'in_stock_delivery_text' => '5-10 d.d.',
            'backorder_delivery_text' => null,
            'allow_backorder_export' => false,
            'availability_fallback_quantity' => 5,
            'sync_enabled' => true,
            'sync_interval_minutes' => 1440,
            'stale_after_minutes' => 1800,
        ]);
    }

    public function ensureHelikSupplier(): Supplier
    {
        return $this->ensureSupplier(Supplier::CODE_HELIK, [
            'name' => 'Helikon / Direct-Action',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_HELIK_API,
            'endpoint_url' => 'https://api.entirem.com/api/v1/stocks',
            'auth_type' => Supplier::AUTH_BEARER_TOKEN,
            'stock_priority' => 100,
            'in_stock_delivery_text' => '5-10 d.d.',
            'backorder_delivery_text' => null,
            'allow_backorder_export' => false,
            'availability_fallback_quantity' => 5,
            'sync_enabled' => true,
            'sync_interval_minutes' => 720,
            'stale_after_minutes' => 1800,
            'config' => [
                'response_type' => 'json',
                'response_data_path' => 'Value',
                'json_data_path' => 'Value',
                'json_sku_path' => 'SKU',
                'json_stock_path' => 'Quantity',
                'method' => 'POST',
                'request_body' => [
                    'Items' => [],
                    'Categories' => [],
                ],
            ],
        ]);
    }

    public function ensurePreziosoSupplier(): Supplier
    {
        $existing = Supplier::query()->where('code', Supplier::CODE_PREZIOSO)->first();

        $operationalConfig = [
            'response_type' => 'csv',
            'method' => 'GET',
            'csv_delimiter' => 'auto',
            'csv_encoding' => 'auto',
            'csv_has_header' => true,
            'csv_data_start_row' => 1,
            'matching_strategy' => 'sku_global',
            'match_by_barcode' => false,
            'require_vendor_scope' => false,
            'vendor_scope' => [],
            'missing_from_feed_policy' => 'mark_unavailable',
        ];

        $columnKeys = [
            'csv_sku_column',
            'csv_barcode_column',
            'csv_stock_column',
            'csv_availability_column',
            'csv_title_column',
            'csv_price_column',
            'csv_vendor_column',
        ];

        if ($existing === null) {
            $config = array_merge($operationalConfig, array_fill_keys($columnKeys, null));
        } else {
            $existingConfig = is_array($existing->config) ? $existing->config : [];
            $config = array_merge($operationalConfig, $existingConfig);

            foreach ($columnKeys as $key) {
                if (array_key_exists($key, $existingConfig)) {
                    $config[$key] = $existingConfig[$key];
                }
            }
        }

        return $this->ensureSupplier(Supplier::CODE_PREZIOSO, [
            'name' => 'Prezioso',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV',
            'auth_type' => Supplier::AUTH_NTLM,
            'stock_priority' => 100,
            'in_stock_delivery_text' => '5-10 d.d.',
            'backorder_delivery_text' => null,
            'allow_backorder_export' => false,
            'availability_fallback_quantity' => 5,
            'sync_enabled' => true,
            'sync_interval_minutes' => 1440,
            'stale_after_minutes' => 1800,
            'config' => $config,
        ]);
    }
}
