<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PiguDimensionRule extends Model
{
    protected $fillable = [
        'match_type',
        'match_value',
        'weight_kg',
        'length_m',
        'width_m',
        'height_m',
        'enabled',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'length_m' => 'float',
            'width_m' => 'float',
            'height_m' => 'float',
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
