<?php

namespace App\Services\Marketplace\Translations;

use App\Enums\MarketplaceTranslationStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Models\MarketplaceTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TranslationRetryService
{
    /**
     * @return array{
     *     selected: int,
     *     queued: int,
     *     skipped: int,
     *     dry_run: bool,
     *     groups: array<int, array{field: string, error: string, count: int}>
     * }
     */
    public function retryFailed(
        string $marketplace = 'ebay',
        string $locale = 'en',
        ?string $reason = null,
        int $limit = 100,
        bool $dryRun = false,
    ): array {
        $limit = max(1, $limit);

        $candidates = $this->candidateQuery($marketplace, $locale, $reason)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $groups = $candidates
            ->groupBy(fn (MarketplaceTranslation $row): string => ($row->field ?? '—')."\0".($row->error_message ?? '—'))
            ->map(function (Collection $rows, string $key): array {
                [$field, $error] = explode("\0", $key, 2);

                return [
                    'field' => $field,
                    'error' => $error,
                    'count' => $rows->count(),
                ];
            })
            ->values()
            ->all();

        if ($dryRun) {
            return [
                'selected' => $candidates->count(),
                'queued' => 0,
                'skipped' => 0,
                'dry_run' => true,
                'groups' => $groups,
            ];
        }

        $queued = 0;
        $skipped = 0;

        foreach ($candidates as $translation) {
            if (! $this->shouldRetry($translation)) {
                $skipped++;

                continue;
            }

            // Preserve any prior translated_text; only reset status/error for re-queue.
            $translation->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => null,
            ]);

            TranslateProductFieldJob::dispatch($translation->id);
            $queued++;
        }

        return [
            'selected' => $candidates->count(),
            'queued' => $queued,
            'skipped' => $skipped,
            'dry_run' => false,
            'groups' => $groups,
        ];
    }

    /**
     * @return Builder<MarketplaceTranslation>
     */
    public function candidateQuery(string $marketplace, string $locale, ?string $reason = null): Builder
    {
        $query = MarketplaceTranslation::query()
            ->where('status', MarketplaceTranslationStatus::Failed)
            ->where('marketplace', $marketplace)
            ->where('locale', $locale);

        if (filled($reason)) {
            $query->where('error_message', 'like', '%'.$reason.'%');
        }

        return $query;
    }

    public function shouldRetry(MarketplaceTranslation $translation): bool
    {
        $fresh = $translation->fresh();

        if ($fresh === null) {
            return false;
        }

        if ($fresh->status === MarketplaceTranslationStatus::Queued) {
            return false;
        }

        if ($fresh->status !== MarketplaceTranslationStatus::Failed) {
            return false;
        }

        // Skip when a current successful translation already exists for the same identity.
        $successfulExists = MarketplaceTranslation::query()
            ->whereKeyNot($fresh->id)
            ->where('marketplace', $fresh->marketplace)
            ->where('locale', $fresh->locale)
            ->where('field', $fresh->field)
            ->where('source_text_hash', $fresh->source_text_hash)
            ->where('translatable_type', $fresh->translatable_type)
            ->where('translatable_id', $fresh->translatable_id)
            ->whereIn('status', MarketplaceTranslationStatus::usableValues())
            ->exists();

        return ! $successfulExists;
    }
}
