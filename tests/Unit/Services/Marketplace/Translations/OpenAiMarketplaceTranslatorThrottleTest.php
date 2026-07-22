<?php

namespace Tests\Unit\Services\Marketplace\Translations;

use App\Services\Marketplace\Translations\OpenAiMarketplaceTranslator;
use App\Services\Marketplace\Translations\OpenAiRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OpenAiMarketplaceTranslatorThrottleTest extends TestCase
{
    public function test_http_429_throws_rate_limit_exception(): void
    {
        config([
            'marketplace.translations.openai.api_key' => 'test-key',
            'marketplace.translations.rpm' => 100,
        ]);
        RateLimiter::clear('marketplace-translation-openai-rpm');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(['error' => 'rate'], 429, [
                'Retry-After' => '45',
            ]),
        ]);

        $this->expectException(OpenAiRateLimitException::class);

        try {
            (new OpenAiMarketplaceTranslator)->translate(
                'Unikalus tekstas be glossary mapping xyz',
                'title',
                'lt',
                'en',
                'ebay',
            );
        } catch (OpenAiRateLimitException $exception) {
            $this->assertSame(45, $exception->retryAfterSeconds);
            $this->assertFalse($exception->isLocal);
            throw $exception;
        }
    }

    public function test_local_rpm_throws_local_rate_limit_exception(): void
    {
        config([
            'marketplace.translations.openai.api_key' => 'test-key',
            'marketplace.translations.rpm' => 1,
            'marketplace.translations.retry_delay_seconds' => 60,
        ]);
        RateLimiter::clear('marketplace-translation-openai-rpm');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Translated']]],
            ], 200),
        ]);

        $translator = new OpenAiMarketplaceTranslator;

        $translator->translate('Unikalus tekstas be glossary mapping abc', 'title', 'lt', 'en', 'ebay');

        $this->expectException(OpenAiRateLimitException::class);

        try {
            $translator->translate('Kitas unikalus tekstas be glossary mapping xyz', 'title', 'lt', 'en', 'ebay');
        } catch (OpenAiRateLimitException $exception) {
            $this->assertTrue($exception->isLocal);
            $this->assertGreaterThanOrEqual(60, $exception->retryAfterSeconds);
            $this->assertStringContainsString('local RPM limit', $exception->getMessage());
            throw $exception;
        }
    }
}
