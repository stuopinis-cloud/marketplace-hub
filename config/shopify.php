<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify Admin API
    |--------------------------------------------------------------------------
    */

    'shop' => env('SHOPIFY_SHOP'),

    'client_id' => env('SHOPIFY_CLIENT_ID'),

    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),

    'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),

    /*
    |--------------------------------------------------------------------------
    | Product import GraphQL page sizes
    |--------------------------------------------------------------------------
    |
    | Lower these values if Shopify returns a query cost limit error.
    |
    */

    'product_page_size' => (int) env('SHOPIFY_PRODUCT_PAGE_SIZE', 20),

    'variant_page_size' => (int) env('SHOPIFY_VARIANT_PAGE_SIZE', 50),

    'media_page_size' => (int) env('SHOPIFY_MEDIA_PAGE_SIZE', 5),

    'inventory_level_page_size' => (int) env('SHOPIFY_INVENTORY_LEVEL_PAGE_SIZE', 1),

    'collection_page_size' => (int) env('SHOPIFY_COLLECTION_PAGE_SIZE', 20),

    /*
    |--------------------------------------------------------------------------
    | Access token cache
    |--------------------------------------------------------------------------
    */

    'token_cache_ttl_hours' => 23,

];
