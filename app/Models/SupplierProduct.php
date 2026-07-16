<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    public const string MATCH_STATUS_MATCHED = 'matched';

    public const string MATCH_STATUS_UNMATCHED = 'unmatched';

    public const string MATCH_STATUS_AMBIGUOUS = 'ambiguous';

    public const string MATCH_METHOD_MANUAL = 'manual';

    public const string MATCH_METHOD_SKU = 'sku';

    public const string MATCH_METHOD_SKU_GLOBAL = 'sku_global';

    public const string MATCH_METHOD_BARCODE = 'barcode';

    public const string AVAILABILITY_AVAILABLE = 'available';

    public const string AVAILABILITY_UNAVAILABLE = 'unavailable';

    public const string AVAILABILITY_MISSING_FROM_FEED = 'missing_from_feed';

    protected $fillable = [
        'supplier_id',
        'product_variant_id',
        'supplier_sku',
        'stock_quantity',
        'availability_status',
        'raw_payload',
        'match_status',
        'match_method',
        'price',
        'currency',
        'enabled',
        'last_synced_at',
        'last_seen_at',
        'stale_at',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'integer',
            'price' => 'decimal:2',
            'enabled' => 'boolean',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function isStale(): bool
    {
        if ($this->stale_at !== null && $this->stale_at->isPast()) {
            return true;
        }

        $supplier = $this->relationLoaded('supplier') ? $this->supplier : $this->supplier()->first();

        return $supplier instanceof Supplier && $supplier->isStockStale($this->last_synced_at);
    }
}
