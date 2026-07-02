<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMapping extends Model
{
    protected $fillable = [
        'marketplace_channel_id',
        'source_type',
        'source_value',
        'target_category_path',
        'priority',
        'enabled',
        'export_enabled',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'enabled' => 'boolean',
            'export_enabled' => 'boolean',
        ];
    }

    public function marketplaceChannel(): BelongsTo
    {
        return $this->belongsTo(MarketplaceChannel::class);
    }
}
