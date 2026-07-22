<?php

namespace App\Services\Marketplace\Translations;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiMarketplaceTranslator implements MarketplaceTranslatorInterface
{
    public function __construct(
        private readonly TranslationGlossary $glossary = new TranslationGlossary,
    ) {}

    public function translate(
        string $sourceText,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        ?string $marketplace = null,
    ): string {
        $glossaryHit = $this->glossary->lookup($sourceText);

        if ($glossaryHit !== null) {
            return $glossaryHit;
        }

        if ($this->glossary->isProtectedValue($sourceText, $field)) {
            return $sourceText;
        }

        $apiKey = config('marketplace.translations.openai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured for marketplace translations.');
        }

        $model = (string) config('marketplace.translations.openai.model', 'gpt-4o-mini');
        $timeout = (int) config('marketplace.translations.openai.timeout', 45);

        $response = Http::withToken((string) $apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt($marketplace),
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->userPrompt($sourceText, $field, $sourceLocale, $targetLocale),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI translation request failed with HTTP '.$response->status());
        }

        $translated = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($translated === '') {
            throw new RuntimeException('OpenAI translation returned an empty response.');
        }

        return $translated;
    }

    public function providerName(): string
    {
        return 'openai';
    }

    private function systemPrompt(?string $marketplace): string
    {
        $marketplaceLabel = $marketplace ?: 'marketplace';

        return <<<PROMPT
You translate Lithuanian ecommerce product text into natural English for {$marketplaceLabel} buyers.
Rules:
- Keep brand names, model names, SKUs, sizes, EANs/UPCs, measurements, and standards unchanged (NIJ, MOLLE, NATO, Cordura, Gore-Tex, Ripstop, EDC, IFAK).
- Keep HTML structure if HTML is provided.
- Do not invent specifications or marketing claims absent from the source.
- Use natural tactical/outdoor English terminology.
- Attribute and option values should be short and marketplace-friendly.
- Return only the translated text with no quotes or commentary.
PROMPT;
    }

    private function userPrompt(string $sourceText, string $field, string $sourceLocale, string $targetLocale): string
    {
        return "Field: {$field}\nSource locale: {$sourceLocale}\nTarget locale: {$targetLocale}\n\n{$sourceText}";
    }
}
