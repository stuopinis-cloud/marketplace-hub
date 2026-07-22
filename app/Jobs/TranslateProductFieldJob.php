<?php

namespace App\Jobs;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;
use App\Models\MarketplaceTranslation;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranslateProductFieldJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $translationId,
    ) {}

    public function handle(
        MarketplaceTranslationService $translations,
        MarketplaceTranslatorInterface $translator,
    ): void {
        $translation = MarketplaceTranslation::query()->find($this->translationId);

        if ($translation === null) {
            return;
        }

        if ($translation->isUsable() && $translation->status !== \App\Enums\MarketplaceTranslationStatus::Failed) {
            // Preserve approved/reviewed/manual overrides unless regenerating failed/missing/queued.
            if (in_array($translation->status, [
                \App\Enums\MarketplaceTranslationStatus::Approved,
                \App\Enums\MarketplaceTranslationStatus::Reviewed,
            ], true)) {
                return;
            }
        }

        $translations->withLock($translation, function () use ($translations, $translator, $translation): void {
            $fresh = $translation->fresh();

            if ($fresh === null) {
                return;
            }

            if (in_array($fresh->status, [
                \App\Enums\MarketplaceTranslationStatus::Approved,
                \App\Enums\MarketplaceTranslationStatus::Reviewed,
            ], true)) {
                return;
            }

            try {
                $translations->translateRecord($fresh, $translator);
            } catch (Throwable $exception) {
                // Status already marked failed inside translateRecord.
            }
        });
    }
}
