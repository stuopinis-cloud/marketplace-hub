<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Product extends Model
{
    protected $fillable = [
        'source_id',
        'external_id',
        'title',
        'description_html',
        'vendor',
        'brand',
        'product_type',
        'category',
        'status',
        'varle_export_status',
        'handle',
        'raw_payload',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'varle_export_status' => VarleExportStatus::class,
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceCategories(): BelongsToMany
    {
        return $this->belongsToMany(SourceCategory::class, 'product_source_categories')
            ->withTimestamps();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function inventoryLevels(): HasManyThrough
    {
        return $this->hasManyThrough(InventoryLevel::class, ProductVariant::class, 'product_id', 'variant_id');
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function syncJobItems(): HasMany
    {
        return $this->hasMany(SyncJobItem::class);
    }
}
