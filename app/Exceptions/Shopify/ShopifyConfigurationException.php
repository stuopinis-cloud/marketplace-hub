<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

class ShopifyConfigurationException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self('Shopify is not configured. Set SHOPIFY_SHOP, SHOPIFY_CLIENT_ID, and SHOPIFY_CLIENT_SECRET in your .env file.');
    }

    public static function missingShop(): self
    {
        return new self('Shopify shop is not configured. Set SHOPIFY_SHOP to your myshopify.com domain.');
    }

    public static function missingClientId(): self
    {
        return new self('Shopify client ID is not configured. Set SHOPIFY_CLIENT_ID in your .env file.');
    }

    public static function missingClientSecret(): self
    {
        return new self('Shopify client secret is not configured. Set SHOPIFY_CLIENT_SECRET in your .env file.');
    }
}
