<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'external_id',
        'sku',
        'barcode',
        'title',
        'price',
        'compare_at_price',
        'weight',
        'weight_unit',
        'option1',
        'option1_name',
        'option1_value',
        'option2',
        'option2_name',
        'option2_value',
        'option3',
        'option3_name',
        'option3_value',
        'raw_payload',
        'image_url',
        'image_alt',
        'image_external_id',
        'inventory_policy',
        'backorder_allowed',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'raw_payload' => 'array',
            'backorder_allowed' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class, 'variant_id');
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'product_variant_id');
    }

    public function marketplaceTranslations(): MorphMany
    {
        return $this->morphMany(MarketplaceTranslation::class, 'translatable');
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'variant_id');
    }

    public function syncJobItems(): HasMany
    {
        return $this->hasMany(SyncJobItem::class, 'variant_id');
    }
}
