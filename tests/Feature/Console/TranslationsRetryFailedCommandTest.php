<?php

namespace Tests\Feature\Console;

use App\Enums\MarketplaceTranslationStatus;
use App\Enums\ProductStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Models\Source;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use App\Services\Marketplace\Translations\OpenAiRateLimitException;
use App\Services\Marketplace\Translations\TranslationQueueService;
use App\Services\Marketplace\Translations\TranslationRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class TranslationsRetryFailedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_failed_filters_by_marketplace_locale_reason(): void
    {
        $product = $this->makeProduct();

        $match = $this->makeFailed($product, 'ebay', 'en', 'title', 'OpenAI API key is not configured');
        $this->makeFailed($product, 'ebay', 'en', 'description', 'OpenAI translation request failed with HTTP 429');
        $this->makeFailed($product, 'ebay', 'lt', 'title', 'OpenAI API key is not configured');
        $this->makeFailed($product, 'varle', 'en', 'title', 'OpenAI API key is not configured');

        Queue::fake();

        $this->artisan('translations:retry-failed', [
            '--marketplace' => 'ebay',
            '--locale' => 'en',
            '--reason' => 'API key is not configured',
            '--limit' => 100,
        ])->assertSuccessful();

        Queue::assertPushed(TranslateProductFieldJob::class, 1);
        $this->assertSame(MarketplaceTranslationStatus::Queued, $match->fresh()->status);
    }

    public function test_retry_failed_respects_limit(): void
    {
        $product = $this->makeProduct();

        $this->makeFailed($product, 'ebay', 'en', 'title', 'fail-a');
        $this->makeFailed($product, 'ebay', 'en', 'description', 'fail-b');
        $this->makeFailed($product, 'ebay', 'en', 'option_name:1', 'fail-c');

        Queue::fake();

        $this->artisan('translations:retry-failed', [
            '--marketplace' => 'ebay',
            '--locale' => 'en',
            '--limit' => 2,
        ])->assertSuccessful();

        Queue::assertPushed(TranslateProductFieldJob::class, 2);
        $this->assertSame(2, MarketplaceTranslation::query()->where('status', 'queued')->count());
        $this->assertSame(1, MarketplaceTranslation::query()->where('status', 'failed')->count());
    }

    public function test_dry_run_does_not_change_db(): void
    {
        $product = $this->makeProduct();
        $row = $this->makeFailed($product, 'ebay', 'en', 'title', 'OpenAI API key is not configured');

        Queue::fake();

        $this->artisan('translations:retry-failed', [
            '--marketplace' => 'ebay',
            '--locale' => 'en',
            '--dry-run' => true,
        ])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(MarketplaceTranslationStatus::Failed, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->error_message);
    }

    public function test_real_run_marks_failed_rows_queued_and_dispatches_jobs(): void
    {
        $product = $this->makeProduct();
        $row = $this->makeFailed($product, 'ebay', 'en', 'title', 'boom', translatedText: 'Keep me');

        Queue::fake();

        $this->artisan('translations:retry-failed', [
            '--marketplace' => 'ebay',
            '--locale' => 'en',
            '--limit' => 10,
        ])->assertSuccessful();

        $row->refresh();
        $this->assertSame(MarketplaceTranslationStatus::Queued, $row->status);
        $this->assertNull($row->error_message);
        $this->assertSame('Keep me', $row->translated_text);
        Queue::assertPushed(TranslateProductFieldJob::class, fn (TranslateProductFieldJob $job): bool => $job->translationId === $row->id);
    }

    public function test_retry_failed_skips_rows_that_already_have_current_successful_translation(): void
    {
        $product = $this->makeProduct();
        $failed = $this->makeFailed($product, 'ebay', 'en', 'title', 'old error');

        // Concurrent recovery: row is no longer failed / already successful.
        $failed->update([
            'status' => MarketplaceTranslationStatus::Approved,
            'translated_text' => 'Already good',
            'error_message' => null,
        ]);

        $this->assertFalse(app(TranslationRetryService::class)->shouldRetry($failed->fresh()));

        Queue::fake();

        $result = app(TranslationRetryService::class)->retryFailed('ebay', 'en', null, 10, false);

        $this->assertSame(0, $result['selected']);
        $this->assertSame(0, $result['queued']);
        Queue::assertNothingPushed();
        $this->assertSame(MarketplaceTranslationStatus::Approved, $failed->fresh()->status);
    }

    public function test_queue_missing_respects_limit(): void
    {
        $this->makeProduct();
        $this->makeProduct('Second');
        $this->makeProduct('Third');

        Queue::fake();

        $result = app(TranslationQueueService::class)->queueMissingForMarketplace('ebay', 'en', 2);

        $this->assertSame(2, $result['products_queued']);
        Queue::assertPushed(\App\Jobs\TranslateProductForMarketplaceJob::class, 2);
    }

    public function test_429_releases_job_with_delay_before_final_failure(): void
    {
        config(['marketplace.translations.retries' => 3]);
        RateLimiter::clear('marketplace-translation-openai-rpm');

        $product = $this->makeProduct();
        $row = $this->makeFailed($product, 'ebay', 'en', 'title', 'prior');
        $row->update(['status' => MarketplaceTranslationStatus::Queued, 'error_message' => null]);

        $translator = Mockery::mock(\App\Contracts\Marketplace\MarketplaceTranslatorInterface::class);
        $translator->shouldReceive('providerName')->andReturn('openai');
        $translator->shouldReceive('translate')->once()->andThrow(new OpenAiRateLimitException('HTTP 429', 30));

        $job = Mockery::mock(TranslateProductFieldJob::class, [$row->id])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('tries')->andReturn(3);
        $job->shouldReceive('release')->once()->with(Mockery::on(fn (int $delay): bool => $delay >= 30));

        $job->handle(app(MarketplaceTranslationService::class), $translator);

        $this->assertSame(MarketplaceTranslationStatus::Queued, $row->fresh()->status);
        $this->assertStringContainsString('429', (string) $row->fresh()->error_message);
    }

    public function test_exhausted_retries_marks_failed(): void
    {
        config(['marketplace.translations.retries' => 2]);

        $product = $this->makeProduct();
        $row = $this->makeFailed($product, 'ebay', 'en', 'title', 'prior');
        $row->update(['status' => MarketplaceTranslationStatus::Queued, 'error_message' => null]);

        $translator = Mockery::mock(\App\Contracts\Marketplace\MarketplaceTranslatorInterface::class);
        $translator->shouldReceive('providerName')->andReturn('openai');
        $translator->shouldReceive('translate')->once()->andThrow(new OpenAiRateLimitException('HTTP 429', 10));

        $job = Mockery::mock(TranslateProductFieldJob::class, [$row->id])->makePartial();
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('tries')->andReturn(2);
        $job->shouldReceive('release')->never();

        $job->handle(app(MarketplaceTranslationService::class), $translator);

        $this->assertSame(MarketplaceTranslationStatus::Failed, $row->fresh()->status);
        $this->assertStringContainsString('429', (string) $row->fresh()->error_message);
    }

    private function makeProduct(string $title = 'Product'): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        return Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-'.uniqid(),
            'title' => $title,
            'vendor' => 'Vendor',
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);
    }

    private function makeFailed(
        Product $product,
        string $marketplace,
        string $locale,
        string $field,
        string $error,
        ?string $translatedText = null,
    ): MarketplaceTranslation {
        $source = $field.'-'.$error.'-'.uniqid();

        return MarketplaceTranslation::query()->create([
            'translatable_type' => $product->getMorphClass(),
            'translatable_id' => $product->id,
            'marketplace' => $marketplace,
            'locale' => $locale,
            'field' => $field,
            'source_text_hash' => MarketplaceTranslation::hashSource($source),
            'source_text' => $source,
            'translated_text' => $translatedText,
            'status' => MarketplaceTranslationStatus::Failed,
            'provider' => 'openai',
            'error_message' => $error,
        ]);
    }
}
