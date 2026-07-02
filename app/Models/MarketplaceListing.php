<?php

namespace App\Models;

use App\Enums\MarketplaceListingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'marketplace_channel_id',
        'product_id',
        'variant_id',
        'marketplace_sku',
        'status',
        'last_exported_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceListingStatus::class,
            'last_exported_at' => 'datetime',
        ];
    }

    public function marketplaceChannel(): BelongsTo
    {
        return $this->belongsTo(MarketplaceChannel::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
