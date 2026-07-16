<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

class SupplierProvisioner
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function ensureSupplier(string $code, array $attributes): Supplier
    {
        return Supplier::query()->updateOrCreate(
            ['code' => $code],
            $attributes,
        );
    }

    public function ensureMtacSupplier(): Supplier
    {
        return $this->ensureSupplier(Supplier::CODE_MTAC, [
            'name' => 'M-Tac',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_XML_URL,
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
            'connector_type' => Supplier::CONNECTOR_API,
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
                'response_data_path' => 'Value',
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

        // Column names are mapped in Filament after CSV preview. Never wipe existing mappings.
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
            $config = array_merge($existingConfig, $operationalConfig);

            foreach ($columnKeys as $key) {
                if (array_key_exists($key, $existingConfig)) {
                    $config[$key] = $existingConfig[$key];
                } else {
                    unset($config[$key]);
                }
            }
        }

        $attributes = [
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
        ];

        // Credentials come from PREZIOSO_NTLM_* env (or encrypted Filament credentials later).
        // Never store plaintext password in config JSON.

        return $this->ensureSupplier(Supplier::CODE_PREZIOSO, $attributes);
    }
}
