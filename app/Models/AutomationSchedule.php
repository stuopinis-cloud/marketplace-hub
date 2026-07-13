<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationSchedule extends Model
{
    protected $fillable = [
        'name',
        'type',
        'enabled',
        'frequency',
        'run_time',
        'timezone',
        'run_shopify_import',
        'run_supplier_sync',
        'run_varle_export',
        'generate_failed_csv',
        'last_run_at',
        'next_run_at',
        'last_status',
        'last_error',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'run_shopify_import' => 'boolean',
            'run_supplier_sync' => 'boolean',
            'run_varle_export' => 'boolean',
            'generate_failed_csv' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'config' => 'array',
        ];
    }
}
