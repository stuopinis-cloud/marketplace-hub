<?php

namespace App\Models;

use App\Services\Marketplace\CategoryResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SourceCategory extends Model
{
    protected $fillable = [
        'source_id',
        'type',
        'external_id',
        'name',
        'handle',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_source_categories')
            ->withTimestamps();
    }

    public function mappingSourceValue(): string
    {
        if ($this->type === 'collection' && filled($this->handle)) {
            return (string) $this->handle;
        }

        return (string) $this->name;
    }

    public function selectLabel(): string
    {
        if (filled($this->handle)) {
            return "{$this->name} (handle: {$this->handle})";
        }

        return (string) $this->name;
    }

    public static function findForMapping(string $sourceType, string $sourceValue): ?self
    {
        if (! in_array($sourceType, ['collection', 'product_type', 'tag'], true)) {
            return null;
        }

        $normalized = CategoryResolver::normalizeComparisonValue($sourceValue);

        return static::query()
            ->where('type', $sourceType)
            ->orderBy('name')
            ->get()
            ->first(function (self $category) use ($sourceType, $normalized): bool {
                if ($sourceType === 'collection') {
                    return CategoryResolver::normalizeComparisonValue($category->name) === $normalized
                        || CategoryResolver::normalizeComparisonValue($category->handle) === $normalized;
                }

                return CategoryResolver::normalizeComparisonValue($category->name) === $normalized;
            });
    }
}
