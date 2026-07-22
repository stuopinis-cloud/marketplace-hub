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
            'feed_temp_path' => env('VARLE_FEED_TEMP_PATH', 'feeds/varle.xml.tmp'),
            'public_url' => env('VARLE_FEED_PUBLIC_URL'),
            'export_chunk_size' => (int) env('VARLE_EXPORT_CHUNK_SIZE', 100),
            'store_url' => env('APP_STORE_URL'),
            'vat_rate' => (int) env('VARLE_VAT_RATE', 21),
        ],
        'ebay' => [
            'locale' => env('EBAY_EXPORT_LOCALE', 'en'),
            'currency' => env('EBAY_EXPORT_CURRENCY', 'EUR'),
            'feed_path' => env('EBAY_FEED_PATH', 'feeds/ebay-en.xml'),
            'feed_temp_path' => env('EBAY_FEED_TEMP_PATH', 'feeds/ebay-en.xml.tmp'),
            'public_url' => env('EBAY_FEED_PUBLIC_URL'),
            'title_max_length' => (int) env('EBAY_TITLE_MAX_LENGTH', 80),
            'requires_approved_translations' => env('EBAY_EXPORT_REQUIRES_APPROVED_TRANSLATIONS', false),
        ],
    ],

        'translations' => [
        'provider' => env('MARKETPLACE_TRANSLATION_PROVIDER', 'openai'),
        'source_locale' => env('MARKETPLACE_TRANSLATION_SOURCE_LOCALE', 'lt'),
        'lock_seconds' => (int) env('MARKETPLACE_TRANSLATION_LOCK_SECONDS', 300),
        'rpm' => (int) env('MARKETPLACE_TRANSLATION_RPM', 10),
        'retries' => (int) env('MARKETPLACE_TRANSLATION_RETRIES', 5),
        'auto_queue_missing_translations_for_ebay' => env('AUTO_QUEUE_MISSING_TRANSLATIONS_FOR_EBAY', false),
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_TRANSLATION_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TRANSLATION_TIMEOUT', 45),
        ],
        'protected_terms' => [
            'NIJ', 'MOLLE', 'NATO', 'Cordura', 'Gore-Tex', 'Gore Tex', 'Ripstop', 'EDC', 'IFAK',
        ],
        'glossary' => [
            'spalva' => 'Color',
            'dydis' => 'Size',
            'juoda' => 'Black',
            'juodos' => 'Black',
            'žalia' => 'Green',
            'zalia' => 'Green',
            'pilka' => 'Grey',
            'smėlio' => 'Coyote',
            'smelio' => 'Coyote',
            'kuprinė' => 'Backpack',
            'kuprines' => 'Backpack',
            'kelnės' => 'Pants',
            'kelnes' => 'Pants',
            'striukė' => 'Jacket',
            'striuke' => 'Jacket',
            'pirštinės' => 'Gloves',
            'pirstines' => 'Gloves',
            'dėklas' => 'Pouch',
            'deklas' => 'Pouch',
        ],
    ],

    'sync' => [
        'stuck_after_minutes' => (int) env('SYNC_STUCK_AFTER_MINUTES', 10),
    ],

    'daily_sync' => [
        'enabled' => env('MARKETPLACE_DAILY_SYNC_ENABLED', true),
        'time' => env('MARKETPLACE_DAILY_SYNC_TIME', '03:00'),
        'timezone' => 'Europe/Vilnius',
    ],

    'suppliers' => [
        'csv_max_upload_kb' => (int) env('SUPPLIER_CSV_MAX_UPLOAD_KB', 10240),
    ],

];
