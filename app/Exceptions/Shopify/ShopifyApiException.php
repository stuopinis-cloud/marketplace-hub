<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    public static function fromHttpStatus(int $status, string $body): self
    {
        $message = match (true) {
            $status === 401 => 'Shopify authentication failed. The access token may be invalid or expired.',
            $status === 403 => 'Shopify access denied. The app may lack required API scopes.',
            $status === 404 => 'Shopify shop or API version not found. Check SHOPIFY_SHOP and SHOPIFY_API_VERSION.',
            $status >= 500 => 'Shopify returned a server error. Try again shortly.',
            default => 'Shopify API request failed.',
        };

        $detail = self::sanitizeBody($body);

        if ($detail !== '') {
            $message .= ' '.$detail;
        }

        return new self($message, $status);
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('Shopify returned an invalid response: '.$reason);
    }

    private static function sanitizeBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['errors']) && is_string($decoded['errors'])) {
            return $decoded['errors'];
        }

        if (strlen($body) > 200) {
            return substr($body, 0, 200).'...';
        }

        return $body;
    }
}
