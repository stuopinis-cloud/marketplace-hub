<?php

namespace App\Contracts\Marketplace;

interface MarketplaceTranslatorInterface
{
    /**
     * Translate marketplace product text. Must keep protected terms unchanged.
     */
    public function translate(
        string $sourceText,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        ?string $marketplace = null,
    ): string;

    public function providerName(): string;
}
