<?php

namespace App\Models\Relations;

use App\Models\SyncJob;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Supplier sync jobs are not linked by a foreign key: the `SyncJob.source` column
 * stores values like `supplier:mtac`, `supplier:helik`, or `supplier:{connector}:{code}`
 * (see the various SupplierXxxImporter classes). This relation matches on that
 * convention instead of a plain foreign-key equality check.
 *
 * @extends HasMany<SyncJob, \App\Models\Supplier>
 */
class SupplierSyncJobsRelation extends HasMany
{
    public function addConstraints(): void
    {
        if (! static::$constraints) {
            return;
        }

        $code = (string) $this->getParentKey();

        $this->getRelationQuery()->where(fn ($query) => $this->applyCodeConstraint($query, $code));
    }

    /**
     * @param  array<int, \App\Models\Supplier>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $codes = (new Collection($models))
            ->map(fn ($model) => (string) $model->getAttribute($this->localKey))
            ->filter()
            ->unique()
            ->values();

        $this->getRelationQuery()->where(function ($query) use ($codes): void {
            foreach ($codes as $code) {
                $query->orWhere(fn ($inner) => $this->applyCodeConstraint($inner, $code));
            }
        });
    }

    /**
     * @param  array<int, \App\Models\Supplier>  $models
     * @param  Collection<int, SyncJob>  $results
     * @return array<int, \App\Models\Supplier>
     */
    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $code = (string) $model->getAttribute($this->localKey);

            $matched = $results->filter(fn (SyncJob $job): bool => $this->sourceMatchesCode((string) $job->source, $code))->values();

            $model->setRelation($relation, $matched);
        }

        return $models;
    }

    private function applyCodeConstraint(mixed $query, string $code): void
    {
        $query
            ->where($this->foreignKey, 'supplier:'.$code)
            ->orWhere($this->foreignKey, 'like', 'supplier:%:'.$code);
    }

    private function sourceMatchesCode(string $source, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return $source === 'supplier:'.$code
            || (str_starts_with($source, 'supplier:') && str_ends_with($source, ':'.$code));
    }
}
