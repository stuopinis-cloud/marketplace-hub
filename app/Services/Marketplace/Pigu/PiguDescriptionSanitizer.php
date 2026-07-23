<?php

namespace App\Services\Marketplace\Pigu;

class PiguDescriptionSanitizer
{
    private const ALLOWED_TAGS = '<p><br><b><strong><i><em><ul><ol><li><div><span><h2><h3>';

    public function sanitize(?string $html): string
    {
        $value = (string) $html;

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", '&nbsp;', '&sdot;', '⋅'], [' ', ' ', '•', '•'], $value);

        $value = preg_replace('#<(script|iframe|style|object|embed)[^>]*>.*?</\1>#is', '', $value) ?? $value;
        $value = preg_replace('#<(script|iframe|style|object|embed)[^>]*/?>#is', '', $value) ?? $value;
        $value = preg_replace('#<a\b[^>]*>.*?</a>#is', '', $value) ?? $value;
        $value = preg_replace('#<img\b[^>]*/?>#is', '', $value) ?? $value;
        $value = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value) ?? $value;
        $value = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $value) ?? $value;

        $value = strip_tags($value, self::ALLOWED_TAGS);
        $value = preg_replace("/[\r\n]+/", "\n", $value) ?? $value;

        return trim($value);
    }

    public function cdataSafe(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
