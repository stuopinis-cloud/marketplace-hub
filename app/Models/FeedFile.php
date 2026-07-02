<?php

namespace App\Models;

use App\Enums\FeedFileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedFile extends Model
{
    protected $fillable = [
        'marketplace_channel_id',
        'filename',
        'path',
        'public_url',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FeedFileStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    public function marketplaceChannel(): BelongsTo
    {
        return $this->belongsTo(MarketplaceChannel::class);
    }
}
