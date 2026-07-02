<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceChannel extends Model
{
    protected $fillable = [
        'name',
        'type',
        'enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function feedFiles(): HasMany
    {
        return $this->hasMany(FeedFile::class);
    }

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(CategoryMapping::class);
    }
}
