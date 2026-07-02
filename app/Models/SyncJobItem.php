<?php

namespace App\Models;

use App\Enums\SyncJobItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncJobItem extends Model
{
    protected $fillable = [
        'sync_job_id',
        'product_id',
        'variant_id',
        'sku',
        'status',
        'message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncJobItemStatus::class,
            'payload' => 'array',
        ];
    }

    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
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
