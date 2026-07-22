<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'varle_is_ready',
        'varle_issue_count',
        'varle_issue_codes',
        'varle_barcode_status',
        'varle_image_status',
        'varle_category_status',
        'varle_stock_status',
        'varle_vendor_delivery_rule_status',
        'varle_delivery_text_preview',
        'varle_mapped_category_preview',
        'varle_exportable_variants_count',
        'varle_skipped_variants_count',
        'varle_readiness_cached_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'varle_export_status' => VarleExportStatus::class,
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
            'varle_is_ready' => 'boolean',
            'varle_issue_count' => 'integer',
            'varle_issue_codes' => 'array',
            'varle_exportable_variants_count' => 'integer',
            'varle_skipped_variants_count' => 'integer',
            'varle_readiness_cached_at' => 'datetime',
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

    public function marketplaceTranslations(): MorphMany
    {
        return $this->morphMany(MarketplaceTranslation::class, 'translatable');
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
