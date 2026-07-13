<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    public const string CODE_MTAC = 'mtac';

    public const string CODE_HELIK = 'helik';

    public const string CONNECTOR_XML_URL = 'xml_url';

    public const string CONNECTOR_API = 'api';

    public const string AUTH_NONE = 'none';

    public const string AUTH_BEARER_TOKEN = 'bearer_token';

    protected $fillable = [
        'name',
        'code',
        'enabled',
        'connector_type',
        'endpoint_url',
        'auth_type',
        'credentials',
        'stock_priority',
        'in_stock_delivery_text',
        'backorder_delivery_text',
        'allow_backorder_export',
        'availability_fallback_quantity',
        'sync_enabled',
        'sync_interval_minutes',
        'stale_after_minutes',
        'last_sync_at',
        'last_sync_status',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'stock_priority' => 'integer',
            'allow_backorder_export' => 'boolean',
            'availability_fallback_quantity' => 'integer',
            'sync_enabled' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'stale_after_minutes' => 'integer',
            'last_sync_at' => 'datetime',
            'config' => 'array',
        ];
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function isStockStale(?\Illuminate\Support\Carbon $lastSyncedAt): bool
    {
        if ($lastSyncedAt === null) {
            return true;
        }

        if ($this->stale_after_minutes === null) {
            return false;
        }

        return $lastSyncedAt->lt(now()->subMinutes($this->stale_after_minutes));
    }
}
