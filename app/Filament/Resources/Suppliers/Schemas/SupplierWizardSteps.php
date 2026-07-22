<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierFormDataFactory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Carbon;

class SupplierWizardSteps
{
    /**
     * @var array<int, string>
     */
    private const array ENDPOINT_CONNECTORS = [
        Supplier::CONNECTOR_XML_URL,
        Supplier::CONNECTOR_API,
        Supplier::CONNECTOR_JSON_API,
        Supplier::CONNECTOR_CSV_URL,
        Supplier::CONNECTOR_MTAC,
        Supplier::CONNECTOR_HELIK_API,
    ];

    /**
     * @var array<int, string>
     */
    private const array AUTH_CAPABLE_CONNECTORS = [
        Supplier::CONNECTOR_XML_URL,
        Supplier::CONNECTOR_API,
        Supplier::CONNECTOR_JSON_API,
        Supplier::CONNECTOR_CSV_URL,
    ];

    /**
     * @var array<int, string>
     */
    private const array METHOD_CONNECTORS = [
        Supplier::CONNECTOR_API,
        Supplier::CONNECTOR_JSON_API,
        Supplier::CONNECTOR_HELIK_API,
    ];

    /**
     * @var array<int, string>
     */
    private const array CSV_CONNECTORS = [
        Supplier::CONNECTOR_CSV_URL,
        Supplier::CONNECTOR_CSV_UPLOAD,
    ];

    /**
     * @var array<int, string>
     */
    private const array JSON_CONNECTORS = [
        Supplier::CONNECTOR_JSON_API,
        Supplier::CONNECTOR_API,
        Supplier::CONNECTOR_HELIK_API,
    ];

    /**
     * @return array<int, Step>
     */
    public static function steps(?callable $getLivewire = null): array
    {
        return [
            self::basicsStep(),
            self::connectionStep(),
            self::previewStep($getLivewire),
            self::mappingStep(),
            self::matchingStep(),
            self::schedulingStep(),
            self::validateStep($getLivewire),
        ];
    }

    private static function basicsStep(): Step
    {
        return Step::make('Basics')
            ->description('Identify the supplier and choose how its feed is delivered.')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if (blank($get('code')) && filled($state)) {
                            $set('code', SupplierFormDataFactory::codeFromName($state));
                        }
                    }),
                TextInput::make('code')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Lowercase identifier used by sync commands and job sources. Auto-filled from the name.'),
                Toggle::make('enabled')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive suppliers are hidden from availability calculations.'),
                Toggle::make('sync_enabled')
                    ->label('Sync enabled')
                    ->default(false)
                    ->helperText('Can only be turned on once required connection and mapping fields validate successfully.'),
                Select::make('connector_type')
                    ->label('Connector type')
                    ->options([
                        Supplier::CONNECTOR_XML_URL => 'XML URL',
                        Supplier::CONNECTOR_API => 'API / JSON (legacy)',
                        Supplier::CONNECTOR_JSON_API => 'JSON API',
                        Supplier::CONNECTOR_CSV_URL => 'CSV URL',
                        Supplier::CONNECTOR_CSV_UPLOAD => 'CSV Upload',
                        Supplier::CONNECTOR_MTAC => 'M-Tac (built-in)',
                        Supplier::CONNECTOR_HELIK_API => 'Helikon / Direct-Action (built-in)',
                    ])
                    ->native(false)
                    ->live()
                    ->required()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if (filled($get('code'))) {
                            return;
                        }

                        if ($state === Supplier::CONNECTOR_MTAC) {
                            $set('code', Supplier::CODE_MTAC);
                        } elseif ($state === Supplier::CONNECTOR_HELIK_API) {
                            $set('code', Supplier::CODE_HELIK);
                        }
                    }),
                Select::make('config.response_type')
                    ->label('Feed response format')
                    ->options([
                        'csv' => 'CSV',
                        'xml' => 'XML',
                        'json' => 'JSON',
                    ])
                    ->native(false)
                    ->helperText('Informational metadata only; the connector type above determines the actual parser used.'),
            ])
            ->columns(2);
    }

    private static function connectionStep(): Step
    {
        return Step::make('Connection')
            ->description('Where and how to fetch the feed.')
            ->schema([
                TextInput::make('endpoint_url')
                    ->label('Endpoint URL')
                    ->url()
                    ->columnSpanFull()
                    ->required(fn (Get $get): bool => in_array($get('connector_type'), self::ENDPOINT_CONNECTORS, true))
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::ENDPOINT_CONNECTORS, true)),
                Select::make('config.method')
                    ->label('HTTP method')
                    ->options([
                        'GET' => 'GET',
                        'POST' => 'POST',
                    ])
                    ->default('GET')
                    ->native(false)
                    ->live()
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::METHOD_CONNECTORS, true)),
                Textarea::make('config.request_body_json')
                    ->label('Request body (JSON)')
                    ->helperText('Example: {"Items": [], "Categories": []}')
                    ->rows(4)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::METHOD_CONNECTORS, true)
                        && $get('config.method') === 'POST'),
                Select::make('auth_type')
                    ->label('Authentication')
                    ->options([
                        Supplier::AUTH_NONE => 'None',
                        Supplier::AUTH_BEARER_TOKEN => 'Bearer token',
                        Supplier::AUTH_BASIC => 'Basic auth',
                        Supplier::AUTH_CUSTOM_HEADERS => 'Custom headers',
                        Supplier::AUTH_NTLM => 'NTLM',
                    ])
                    ->default(Supplier::AUTH_NONE)
                    ->native(false)
                    ->live()
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::AUTH_CAPABLE_CONNECTORS, true)),
                TextInput::make('credential_token')
                    ->label('API token')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->afterStateHydrated(function (TextInput $component, mixed $record): void {
                        if (! $record instanceof Supplier) {
                            return;
                        }

                        $component->state(filled(data_get($record->credentials, 'token')) ? '********' : null);
                    })
                    ->helperText('Stored encrypted. Leave blank to keep the existing token or use an environment credential.')
                    ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BEARER_TOKEN),
                TextInput::make('credential_username')
                    ->label('Username')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->afterStateHydrated(function (TextInput $component, mixed $record): void {
                        $component->state(data_get($record instanceof Supplier ? $record->credentials : null, 'username'));
                    })
                    ->helperText(fn (Get $get): ?string => $get('auth_type') === Supplier::AUTH_NTLM
                        ? 'Stored encrypted. Leave blank to use an environment NTLM username.'
                        : null)
                    ->visible(fn (Get $get): bool => in_array($get('auth_type'), [
                        Supplier::AUTH_BASIC,
                        Supplier::AUTH_NTLM,
                    ], true)),
                TextInput::make('credential_password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->afterStateHydrated(function (TextInput $component, mixed $record): void {
                        if (! $record instanceof Supplier) {
                            return;
                        }

                        $component->state(filled(data_get($record->credentials, 'password')) ? '********' : null);
                    })
                    ->helperText(fn (Get $get): ?string => $get('auth_type') === Supplier::AUTH_NTLM
                        ? 'Stored encrypted. Leave blank to keep the existing password or use an environment NTLM password.'
                        : null)
                    ->visible(fn (Get $get): bool => in_array($get('auth_type'), [
                        Supplier::AUTH_BASIC,
                        Supplier::AUTH_NTLM,
                    ], true)),
                TextInput::make('config.request_headers_json')
                    ->label('Custom request headers (JSON object)')
                    ->helperText('Example: {"X-Api-Key":"secret"}')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_CUSTOM_HEADERS),
                FileUpload::make('csv_upload_file')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                    ->disk('local')
                    ->directory('suppliers/csv/uploads')
                    ->maxSize((int) config('marketplace.suppliers.csv_max_upload_kb', 10240))
                    ->helperText('Stored in private local storage. Upload a new file to replace the current feed.')
                    ->visible(fn (Get $get): bool => $get('connector_type') === Supplier::CONNECTOR_CSV_UPLOAD)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function previewStep(?callable $getLivewire = null): Step
    {
        return Step::make('Preview')
            ->description('Fetch a live sample of the feed before mapping fields.')
            ->schema([
                Placeholder::make('preview_info')
                    ->label('Feed preview')
                    ->content(function () use ($getLivewire): string {
                        $livewire = $getLivewire ? $getLivewire() : null;
                        $record = $livewire?->getRecord();

                        if (! $record instanceof Supplier || ! $record->exists) {
                            return 'Save this supplier first, then use the "Preview feed" button in the page header to fetch a live sample of the connector, endpoint, and credentials configured above.';
                        }

                        return 'Use the "Preview feed" button in the page header to fetch a live sample of the connector, endpoint, and credentials configured above. Preview results show detected columns/paths and a handful of sample rows without changing stored data.';
                    }),
            ]);
    }

    private static function mappingStep(): Step
    {
        return Step::make('Mapping')
            ->description('Map supplier feed columns/paths to SKU, stock, and availability.')
            ->schema([
                Section::make('CSV field mapping')
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::CSV_CONNECTORS, true))
                    ->columns(2)
                    ->schema([
                        Select::make('config.csv_delimiter')
                            ->label('Delimiter')
                            ->options([
                                'auto' => 'Auto-detect',
                                'comma' => 'Comma',
                                'semicolon' => 'Semicolon',
                                'tab' => 'Tab',
                                'pipe' => 'Pipe',
                            ])
                            ->native(false)
                            ->default('comma'),
                        Select::make('config.csv_encoding')
                            ->label('Encoding')
                            ->options([
                                'auto' => 'Auto-detect',
                                'UTF-8' => 'UTF-8',
                                'Windows-1252' => 'Windows-1252',
                                'ISO-8859-1' => 'ISO-8859-1',
                                'Windows-1257' => 'Windows-1257',
                                'ISO-8859-13' => 'ISO-8859-13',
                            ])
                            ->native(false)
                            ->default('UTF-8'),
                        TextInput::make('config.csv_enclosure')->label('Enclosure')->default('"')->maxLength(1),
                        TextInput::make('config.csv_escape')->label('Escape')->default('\\')->maxLength(1),
                        Toggle::make('config.csv_has_header')->label('First row is header')->default(true),
                        TextInput::make('config.csv_data_start_row')->label('Data start row')->numeric()->default(1),
                        TextInput::make('config.csv_sku_column')
                            ->label('SKU column')
                            ->required()
                            ->helperText('Header name or 0-based index of the column containing the supplier SKU.'),
                        TextInput::make('config.csv_stock_column')->label('Stock column'),
                        TextInput::make('config.csv_availability_column')->label('Availability column'),
                        TextInput::make('config.csv_barcode_column')->label('Barcode column'),
                        TextInput::make('config.csv_vendor_column')->label('Vendor column'),
                        TextInput::make('config.csv_title_column')->label('Title / name column'),
                        TextInput::make('config.csv_price_column')->label('Cost / price column'),
                    ]),
                Section::make('XML field mapping')
                    ->visible(fn (Get $get): bool => $get('connector_type') === Supplier::CONNECTOR_XML_URL)
                    ->description('Paths are relative XPath expressions evaluated against each item node. M-Tac-coded suppliers fall back to built-in Google Atom paths when these are left empty.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('config.xml_item_path')
                            ->label('Item XPath')
                            ->helperText('Path to each repeating product node, evaluated from the document root. Example: //product or //atom:entry')
                            ->columnSpanFull(),
                        TextInput::make('config.xml_sku_path')
                            ->label('SKU path')
                            ->helperText('XPath relative to each item node, e.g. sku or @sku.'),
                        TextInput::make('config.xml_stock_path')
                            ->label('Stock path')
                            ->helperText('Relative XPath to a numeric stock quantity node.'),
                        TextInput::make('config.xml_availability_path')
                            ->label('Availability path')
                            ->helperText('Relative XPath to a boolean/text availability node, used when stock quantity is absent.'),
                        TextInput::make('config.xml_barcode_path')->label('Barcode path')->helperText('Relative XPath to an EAN/barcode node.'),
                        TextInput::make('config.xml_title_path')->label('Title path')->helperText('Relative XPath to the product title/name node.'),
                        KeyValue::make('config.xml_namespaces')
                            ->label('XML namespaces')
                            ->keyLabel('Prefix')
                            ->valueLabel('URI')
                            ->helperText('Register any XML namespace prefixes used in your XPath expressions above, e.g. atom => http://www.w3.org/2005/Atom.')
                            ->columnSpanFull(),
                    ]),
                Section::make('JSON field mapping')
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), self::JSON_CONNECTORS, true))
                    ->description('Paths are dot-notation keys evaluated against the decoded JSON response. Helikon / Direct-Action built-in suppliers already ship with working defaults.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('config.json_data_path')
                            ->label('Data (list) path')
                            ->helperText('Dot path to the array of product rows in the response, e.g. Value or data.items.')
                            ->columnSpanFull(),
                        TextInput::make('config.json_sku_path')->label('SKU path')->helperText('Dot path relative to each row, e.g. SKU.'),
                        TextInput::make('config.json_stock_path')->label('Stock path')->helperText('Dot path relative to each row, e.g. Quantity.'),
                        TextInput::make('config.json_availability_path')->label('Availability path'),
                        TextInput::make('config.json_barcode_path')->label('Barcode path'),
                        TextInput::make('config.json_title_path')->label('Title path'),
                    ]),
                Placeholder::make('mapping_builtin_note')
                    ->label('Built-in connector')
                    ->visible(fn (Get $get): bool => in_array($get('connector_type'), [Supplier::CONNECTOR_MTAC, Supplier::CONNECTOR_HELIK_API], true))
                    ->content('This connector uses a built-in adapter with pre-configured field mapping. Custom XML/JSON path overrides above are optional and only needed for non-standard feeds.'),
            ]);
    }

    private static function matchingStep(): Step
    {
        return Step::make('Matching')
            ->description('Control how supplier SKUs are matched to Shopify variants.')
            ->schema([
                Select::make('config.matching_strategy')
                    ->label('Matching strategy')
                    ->options([
                        'sku_global' => 'Global SKU',
                        'scoped_default' => 'Scoped default',
                    ])
                    ->default('sku_global')
                    ->native(false)
                    ->live()
                    ->helperText('Global SKU ignores vendor and matches the supplier SKU directly to any Shopify variant SKU. Scoped default restricts matching to the vendor scope below.')
                    ->columnSpanFull(),
                Toggle::make('config.match_by_barcode')
                    ->label('Match by barcode')
                    ->default(false)
                    ->helperText('When enabled, barcode matching is attempted before SKU matching.'),
                Toggle::make('config.require_vendor_scope')
                    ->label('Require vendor scope')
                    ->default(false)
                    ->helperText('Disable for Global SKU matching. Enable to restrict scoped matching to the vendors listed below.'),
                TextInput::make('config.vendor_scope')
                    ->label('Vendor scope (comma-separated)')
                    ->default('')
                    ->helperText('Used by scoped matching only. Leave empty for Global SKU.')
                    ->formatStateUsing(fn (mixed $state): ?string => is_array($state) ? implode(', ', $state) : (is_string($state) ? $state : null))
                    ->dehydrateStateUsing(function (mixed $state): array {
                        if (is_array($state)) {
                            return array_values(array_filter(array_map(
                                fn (mixed $vendor): string => trim((string) $vendor),
                                $state,
                            )));
                        }

                        if (blank($state)) {
                            return [];
                        }

                        return array_values(array_filter(array_map(
                            fn (string $vendor): string => trim($vendor),
                            explode(',', (string) $state),
                        )));
                    })
                    ->columnSpanFull(),
                Select::make('config.missing_from_feed_policy')
                    ->label('Missing from feed policy')
                    ->options([
                        'mark_unavailable' => 'Mark missing rows unavailable',
                        'keep_previous' => 'Keep previous state',
                    ])
                    ->default('mark_unavailable')
                    ->native(false)
                    ->helperText('Applies to supplier products that were previously seen but are absent from the latest full sync.'),
                Select::make('config.duplicate_sku_behavior')
                    ->label('Duplicate SKU behavior')
                    ->options([
                        'flag_ambiguous' => 'Flag as ambiguous (default)',
                        'keep_first' => 'Keep first occurrence',
                        'skip' => 'Skip duplicate rows',
                    ])
                    ->default('flag_ambiguous')
                    ->native(false)
                    ->helperText('Optional. Reserved for feeds with duplicate SKU rows; duplicates are always reported in dry runs.'),
            ])
            ->columns(2);
    }

    private static function schedulingStep(): Step
    {
        return Step::make('Scheduling')
            ->description('Control the daily/scheduled sync cadence.')
            ->schema([
                TextInput::make('sync_interval_minutes')
                    ->label('Sync interval minutes')
                    ->numeric()
                    ->helperText('Skipped during daily sync when the last sync is newer than this interval, unless forced.'),
                Toggle::make('force_daily_sync')
                    ->label('Force every daily sync')
                    ->helperText('Ignore the sync interval and always run during the marketplace daily sync.'),
                Toggle::make('config.block_daily_sync_on_failure')
                    ->label('Block daily sync on failure')
                    ->helperText('When enabled, a failed sync stops readiness refresh and Varle export for this run.'),
                TextInput::make('stale_after_minutes')
                    ->label('Stale after minutes')
                    ->numeric()
                    ->helperText('Supplier stock is treated as stale after this many minutes without a successful sync.'),
                TextInput::make('availability_fallback_quantity')
                    ->label('Availability fallback quantity')
                    ->numeric()
                    ->default(5)
                    ->helperText('Quantity used only when the supplier reports positive boolean/text availability but no numeric stock quantity.'),
                TextInput::make('last_sync_at')
                    ->label('Last synced at')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (mixed $state): ?string => $state instanceof Carbon
                        ? $state->toDateTimeString()
                        : (is_string($state) ? $state : null)),
                TextInput::make('last_sync_status')
                    ->label('Last sync status')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('last_sync_message')
                    ->label('Last sync message')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function validateStep(?callable $getLivewire = null): Step
    {
        return Step::make('Validate')
            ->description('Review readiness before enabling scheduled sync.')
            ->schema([
                Placeholder::make('validate_info')
                    ->label('Dry run')
                    ->content(function () use ($getLivewire): string {
                        $livewire = $getLivewire ? $getLivewire() : null;
                        $record = $livewire?->getRecord();

                        if (! $record instanceof Supplier || ! $record->exists) {
                            return 'Save this supplier first, then use the "Dry run" button in the page header to parse the feed and preview SKU matching results without writing any stock changes.';
                        }

                        return 'Use the "Dry run" button in the page header to parse the feed and preview SKU matching results (matched / unmatched / ambiguous) without writing any stock changes.';
                    }),
                Toggle::make('sync_enabled')
                    ->label('Enable scheduled sync')
                    ->default(false)
                    ->helperText('Validated on save against required connection, credential, and mapping fields. If validation fails, the save is blocked and the offending fields are highlighted.'),
            ]);
    }
}
