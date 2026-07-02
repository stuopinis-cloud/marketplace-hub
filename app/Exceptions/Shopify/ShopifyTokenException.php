<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

class ShopifyTokenException extends RuntimeException
{
    public static function exchangeFailed(int $status, string $body): self
    {
        $message = match (true) {
            $status === 401, $status === 403 => 'Shopify client credentials were rejected. Check SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET.',
            $status === 404 => 'Shopify OAuth endpoint not found. Check SHOPIFY_SHOP.',
            $status >= 500 => 'Shopify token endpoint returned a server error. Try again shortly.',
            default => 'Shopify access token exchange failed.',
        };

        $detail = self::sanitizeBody($body);

        if ($detail !== '') {
            $message .= ' '.$detail;
        }

        return new self($message, $status);
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('Shopify token exchange returned an invalid response: '.$reason);
    }

    public static function missingAccessToken(): self
    {
        return new self('Shopify token exchange succeeded but no access_token was returned.');
    }

    private static function sanitizeBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '';
        }

        if (strlen($body) > 200) {
            return substr($body, 0, 200).'...';
        }

        return $body;
    }
}
