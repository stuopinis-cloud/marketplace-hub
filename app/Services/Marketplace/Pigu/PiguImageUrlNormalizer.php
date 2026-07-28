<?php

namespace App\Services\Marketplace\Pigu;

class PiguImageUrlNormalizer
{
    /**
     * @return array{url: ?string, skipped: bool, reason: ?string, transformed: bool}
     */
    public function normalize(string $url): array
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return ['url' => null, 'skipped' => true, 'reason' => 'empty_url', 'transformed' => false];
        }

        if (! str_starts_with(strtolower($trimmed), 'https://')) {
            return ['url' => null, 'skipped' => true, 'reason' => 'not_https', 'transformed' => false];
        }

        if ($this->isAllowedImageUrl($trimmed)) {
            return ['url' => $trimmed, 'skipped' => false, 'reason' => null, 'transformed' => false];
        }

        if ($this->isWebpUrl($trimmed)) {
            $transformed = $this->transformShopifyWebpToJpg($trimmed);

            if ($transformed !== null && $this->isAllowedImageUrl($transformed)) {
                return ['url' => $transformed, 'skipped' => false, 'reason' => null, 'transformed' => true];
            }

            return ['url' => null, 'skipped' => true, 'reason' => 'invalid_image_format_webp', 'transformed' => false];
        }

        return ['url' => null, 'skipped' => true, 'reason' => 'invalid_image_format', 'transformed' => false];
    }

    /**
     * @param  list<string>  $urls
     * @return array{urls: list<string>, warnings: list<array{url: string, reason: string}>}
     */
    public function normalizeMany(array $urls): array
    {
        $accepted = [];
        $warnings = [];
        $seen = [];

        foreach ($urls as $url) {
            $result = $this->normalize((string) $url);

            if ($result['skipped']) {
                $warnings[] = [
                    'url' => (string) $url,
                    'reason' => (string) ($result['reason'] ?? 'invalid_image_format'),
                ];

                continue;
            }

            $normalized = (string) $result['url'];

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $accepted[] = $normalized;
        }

        return ['urls' => $accepted, 'warnings' => $warnings];
    }

    public function isAllowedImageUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('/\.(jpe?g|png)(\?|$)/i', $path)
            || (bool) preg_match('/[?&]format=(jpe?g|png)\b/i', $url);
    }

    public function isWebpUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return str_contains($path, '.webp')
            || (bool) preg_match('/[?&]format=webp\b/i', $url);
    }

    public function transformShopifyWebpToJpg(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        if ($host === '' || (! str_contains($host, 'shopify') && ! str_contains($host, 'myshopify'))) {
            return null;
        }

        $transformed = preg_replace('/\.webp(\?|$)/i', '.jpg$1', $url) ?? $url;

        if ($transformed === $url) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $transformed = $url.$separator.'format=jpg';
        }

        return $transformed !== $url ? $transformed : null;
    }
}
