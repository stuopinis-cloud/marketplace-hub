<?php

namespace App\Models;

use App\Enums\SyncJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncJob extends Model
{
    protected $fillable = [
        'type',
        'source',
        'channel',
        'status',
        'total_items',
        'success_items',
        'failed_items',
        'started_at',
        'finished_at',
        'cancel_requested_at',
        'cancelled_at',
        'heartbeat_at',
        'process_id',
        'error_message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncJobStatus::class,
            'total_items' => 'integer',
            'success_items' => 'integer',
            'failed_items' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'process_id' => 'integer',
            'context' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncJobItem::class);
    }
}
