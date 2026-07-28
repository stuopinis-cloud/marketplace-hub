<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PiguPropertyRule extends Model
{
    public const string VALUE_SOURCE_VENDOR = 'vendor';

    public const string VALUE_SOURCE_FIXED = 'fixed';

    protected $fillable = [
        'pigu_category_id',
        'match_type',
        'match_value',
        'property_name',
        'property_id',
        'value_source',
        'value_name',
        'enabled',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'pigu_category_id' => 'integer',
            'property_id' => 'integer',
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
