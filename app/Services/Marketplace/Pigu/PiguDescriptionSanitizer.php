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

        $value = $this->convertBulletLinesToList($value);

        $value = strip_tags($value, self::ALLOWED_TAGS);
        $value = preg_replace("/[\r\n]+/", "\n", $value) ?? $value;
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public function cdataSafe(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }

    private function convertBulletLinesToList(string $html): string
    {
        // Convert consecutive lines starting with • / - / * into <ul><li>.
        $pattern = '/(?:(?:^|<br\s*\/?>|\n)\s*[•\-\*]\s+.+(?:(?:<br\s*\/?>|\n)\s*[•\-\*]\s+.+)*)/iu';

        return preg_replace_callback($pattern, function (array $match): string {
            $block = $match[0];
            $items = preg_split('/(?:<br\s*\/?>|\n)\s*/i', $block) ?: [];
            $lis = [];

            foreach ($items as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }

                $item = preg_replace('/^[•\-\*]\s+/u', '', $item) ?? $item;
                $item = trim($item);

                if ($item !== '') {
                    $lis[] = '<li>'.$item.'</li>';
                }
            }

            if (count($lis) < 2) {
                return $block;
            }

            return '<ul>'.implode('', $lis).'</ul>';
        }, $html) ?? $html;
    }
}
