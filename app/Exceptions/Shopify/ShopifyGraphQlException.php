<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

class ShopifyGraphQlException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        $messages = collect($errors)
            ->pluck('message')
            ->filter()
            ->values()
            ->all();

        $message = $messages !== []
            ? implode(' ', $messages)
            : 'Shopify GraphQL request failed.';

        return new self($message);
    }

    public function isQueryCostExceeded(): bool
    {
        $message = strtolower($this->getMessage());

        return str_contains($message, 'query cost') && str_contains($message, 'exceeds');
    }

    public function withQueryCostGuidance(): self
    {
        if (! $this->isQueryCostExceeded()) {
            return $this;
        }

        return new self(
            $this->getMessage().' Try reducing SHOPIFY_PRODUCT_PAGE_SIZE, SHOPIFY_VARIANT_PAGE_SIZE, SHOPIFY_MEDIA_PAGE_SIZE, and SHOPIFY_INVENTORY_LEVEL_PAGE_SIZE in .env, or use Shopify Bulk Operations for large catalogs.',
        );
    }
}
