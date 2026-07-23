<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PiguPropertyMapping extends Model
{
    protected $fillable = [
        'lowest_category_id',
        'field_id',
        'source_type',
        'source_key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'lowest_category_id' => 'integer',
            'field_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
