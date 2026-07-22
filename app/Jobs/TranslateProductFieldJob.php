<?php

namespace App\Jobs;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;
use App\Enums\MarketplaceTranslationStatus;
use App\Models\MarketplaceTranslation;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use App\Services\Marketplace\Translations\OpenAiRateLimitException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranslateProductFieldJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public readonly int $translationId,
    ) {}

    public function tries(): int
    {
        return max(1, (int) config('marketplace.translations.retries', 5));
    }

    public function handle(
        MarketplaceTranslationService $translations,
        MarketplaceTranslatorInterface $translator,
    ): void {
        $translation = MarketplaceTranslation::query()->find($this->translationId);

        if ($translation === null) {
            return;
        }

        if (in_array($translation->status, [
            MarketplaceTranslationStatus::Approved,
            MarketplaceTranslationStatus::Reviewed,
            MarketplaceTranslationStatus::AutoTranslated,
        ], true)) {
            return;
        }

        $translations->withLock($translation, function () use ($translations, $translator, $translation): void {
            $fresh = $translation->fresh();

            if ($fresh === null) {
                return;
            }

            if (in_array($fresh->status, [
                MarketplaceTranslationStatus::Approved,
                MarketplaceTranslationStatus::Reviewed,
                MarketplaceTranslationStatus::AutoTranslated,
            ], true)) {
                return;
            }

            try {
                $translations->translateRecord($fresh, $translator);
            } catch (OpenAiRateLimitException $exception) {
                $this->handleRateLimit($fresh, $exception);
            } catch (Throwable $exception) {
                // Non-rate-limit failures are already marked failed in translateRecord.
            }
        });
    }

    private function handleRateLimit(
        MarketplaceTranslation $translation,
        OpenAiRateLimitException $exception,
    ): void {
        $attempt = $this->attempts();
        $maxTries = $this->tries();

        if ($attempt < $maxTries) {
            $delay = $this->backoffSeconds($attempt, $exception->retryAfterSeconds);

            $translation->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => $exception->getMessage().' (retrying in '.$delay.'s)',
            ]);

            $this->release($delay);

            return;
        }

        $translation->update([
            'status' => MarketplaceTranslationStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }

    private function backoffSeconds(int $attempt, int $retryAfterSeconds): int
    {
        $exponential = (int) min(300, (2 ** max(0, $attempt - 1)) + random_int(0, 5));

        return max($retryAfterSeconds, $exponential);
    }
}
