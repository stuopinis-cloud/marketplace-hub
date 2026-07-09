<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id',
        'product_variant_id',
        'supplier_sku',
        'stock_quantity',
        'price',
        'currency',
        'enabled',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'integer',
            'price' => 'decimal:2',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
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
}
