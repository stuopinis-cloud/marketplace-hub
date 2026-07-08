<?php

namespace App\Models;

use App\Support\MarketplaceChannelConfig;
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

    public function configString(string $key, ?string $default = null): ?string
    {
        return MarketplaceChannelConfig::for($this->config ?? [])->string($key, $default);
    }

    public function configBool(string $key, bool $default = false): bool
    {
        return MarketplaceChannelConfig::for($this->config ?? [])->bool($key, $default);
    }

    public function configInt(string $key, int $default = 0): int
    {
        return MarketplaceChannelConfig::for($this->config ?? [])->int($key, $default);
    }
}
