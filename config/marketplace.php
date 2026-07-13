<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketplace exports
    |--------------------------------------------------------------------------
    */

    'exports' => [
        'varle' => [
            'default_delivery_text' => env('VARLE_DEFAULT_DELIVERY_TEXT', '1-2 d.d.'),
            'feed_path' => env('VARLE_FEED_PATH', 'feeds/varle.xml'),
            'public_url' => env('VARLE_FEED_PUBLIC_URL'),
            'export_chunk_size' => (int) env('VARLE_EXPORT_CHUNK_SIZE', 100),
            'store_url' => env('APP_STORE_URL'),
            'vat_rate' => (int) env('VARLE_VAT_RATE', 21),
        ],
    ],

    'sync' => [
        'stuck_after_minutes' => (int) env('SYNC_STUCK_AFTER_MINUTES', 10),
    ],

];
