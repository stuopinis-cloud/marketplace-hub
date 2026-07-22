<?php

namespace App\Services\Marketplace\Translations;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;

class NoopMarketplaceTranslator implements MarketplaceTranslatorInterface
{
    public function translate(
        string $sourceText,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        ?string $marketplace = null,
    ): string {
        return $sourceText;
    }

    public function providerName(): string
    {
        return 'manual';
    }
}
