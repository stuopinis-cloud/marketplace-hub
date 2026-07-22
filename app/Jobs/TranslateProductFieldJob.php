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
        if ($this->isLocalRateLimit($exception)) {
            $delay = $this->localRetryDelaySeconds($exception->retryAfterSeconds);

            $translation->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => 'OpenAI translation local RPM limit reached, retrying later',
            ]);

            // Soft throttle must never permanently fail the translation row or burn
            // the HTTP-429 attempt budget. Prefer release; re-dispatch when exhausted.
            if ($this->attempts() < $this->tries()) {
                $this->release($delay);
            } else {
                TranslateProductFieldJob::dispatch($this->translationId)
                    ->delay(now()->addSeconds($delay));
            }

            return;
        }

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

    private function isLocalRateLimit(OpenAiRateLimitException $exception): bool
    {
        if ($exception->isLocal) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'local rpm');
    }

    private function localRetryDelaySeconds(int $retryAfterSeconds): int
    {
        $base = max(
            1,
            (int) config('marketplace.translations.retry_delay_seconds', 60),
            $retryAfterSeconds,
        );

        return $base + random_int(0, 15);
    }

    private function backoffSeconds(int $attempt, int $retryAfterSeconds): int
    {
        $base = max(1, (int) config('marketplace.translations.retry_delay_seconds', 60));
        $exponential = (int) min(300, ($base * (2 ** max(0, $attempt - 1))) + random_int(0, 15));

        return max($retryAfterSeconds, $exponential);
    }
}
