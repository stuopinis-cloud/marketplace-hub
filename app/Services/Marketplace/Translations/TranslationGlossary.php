<?php

namespace App\Services\Marketplace\Translations;

use App\Models\MarketplaceTranslation;

class TranslationGlossary
{
    /**
     * @return array<string, string>
     */
    public function termMap(): array
    {
        $configured = config('marketplace.translations.glossary', []);

        return is_array($configured) ? array_change_key_case($configured, CASE_LOWER) : [];
    }

    /**
     * @return list<string>
     */
    public function protectedTerms(): array
    {
        $configured = config('marketplace.translations.protected_terms', []);

        return is_array($configured)
            ? array_values(array_filter(array_map('strval', $configured)))
            : [];
    }

    public function lookup(string $sourceText): ?string
    {
        $normalized = mb_strtolower(trim($sourceText));

        if ($normalized === '') {
            return null;
        }

        $map = $this->termMap();

        return $map[$normalized] ?? null;
    }

    public function isProtectedValue(string $sourceText, string $field): bool
    {
        $trimmed = trim($sourceText);

        if ($trimmed === '') {
            return true;
        }

        if (in_array($field, [
            MarketplaceTranslation::FIELD_OPTION_VALUE,
            MarketplaceTranslation::FIELD_ATTRIBUTE_VALUE,
        ], true) && $this->looksLikeSizeOrCode($trimmed)) {
            return true;
        }

        foreach ($this->protectedTerms() as $term) {
            if (strcasecmp($trimmed, $term) === 0) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeSizeOrCode(string $value): bool
    {
        $trimmed = trim($value);

        if (preg_match('/^(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL)$/iu', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,3}([.,]\d+)?$/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,3}\s?(cm|mm|in|")$/iu', $trimmed) === 1) {
            return true;
        }

        return false;
    }
}
