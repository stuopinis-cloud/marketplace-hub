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
            'sync_enabled' => true,
            'sync_interval_minutes' => 1440,
            'stale_after_minutes' => 1800,
        ]);
    }
}
