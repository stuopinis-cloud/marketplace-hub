<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Actions\PreviewSupplierCsvAction;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            Toggle::make('enabled')->label('Active')->default(true),
            Select::make('connector_type')
                ->options([
                    Supplier::CONNECTOR_XML_URL => 'XML URL',
                    Supplier::CONNECTOR_API => 'API / JSON',
                    Supplier::CONNECTOR_CSV_URL => 'CSV URL',
                    Supplier::CONNECTOR_CSV_UPLOAD => 'CSV Upload',
                ])
                ->live()
                ->required(),
            TextInput::make('endpoint_url')
                ->label('Endpoint URL')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => in_array($get('connector_type'), [
                    Supplier::CONNECTOR_XML_URL,
                    Supplier::CONNECTOR_API,
                    Supplier::CONNECTOR_CSV_URL,
                ], true)),
            Select::make('auth_type')
                ->options([
                    Supplier::AUTH_NONE => 'None',
                    Supplier::AUTH_BEARER_TOKEN => 'Bearer token',
                    Supplier::AUTH_BASIC => 'Basic auth',
                    Supplier::AUTH_CUSTOM_HEADERS => 'Custom headers',
                    Supplier::AUTH_NTLM => 'NTLM',
                ])
                ->visible(fn (Get $get): bool => in_array($get('connector_type'), [
                    Supplier::CONNECTOR_API,
                    Supplier::CONNECTOR_CSV_URL,
                ], true)),
            TextInput::make('credential_token')
                ->label('API token')
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->afterStateHydrated(function (TextInput $component, ?Supplier $record): void {
                    if ($record === null) {
                        return;
                    }

                    $component->state(filled(data_get($record->credentials, 'token')) ? '********' : null);
                })
                ->helperText('Stored encrypted. Leave blank to keep the existing token or use ENTIREM_API_TOKEN from the environment.')
                ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BEARER_TOKEN),
            TextInput::make('credential_username')
                ->label('Username')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->afterStateHydrated(function (TextInput $component, ?Supplier $record): void {
                    $component->state(data_get($record?->credentials, 'username'));
                })
                ->helperText(fn (Get $get): ?string => $get('auth_type') === Supplier::AUTH_NTLM
                    ? 'Stored encrypted. Leave blank to use PREZIOSO_NTLM_USERNAME from the environment.'
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
                ->afterStateHydrated(function (TextInput $component, ?Supplier $record): void {
                    $component->state(filled(data_get($record?->credentials, 'password')) ? '********' : null);
                })
                ->helperText(fn (Get $get): ?string => $get('auth_type') === Supplier::AUTH_NTLM
                    ? 'Stored encrypted. Leave blank to keep the existing password or use PREZIOSO_NTLM_PASSWORD.'
                    : null)
                ->visible(fn (Get $get): bool => in_array($get('auth_type'), [
                    Supplier::AUTH_BASIC,
                    Supplier::AUTH_NTLM,
                ], true)),
            TextInput::make('config.request_headers_json')
                ->label('Custom request headers (JSON object)')
                ->helperText('Example: {"X-Api-Key":"secret"}')
                ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_CUSTOM_HEADERS)
                ->columnSpanFull(),
            Section::make('CSV settings')
                ->visible(fn (Get $get): bool => in_array($get('connector_type'), [
                    Supplier::CONNECTOR_CSV_URL,
                    Supplier::CONNECTOR_CSV_UPLOAD,
                ], true))
                ->columns(2)
                ->schema([
                    FileUpload::make('csv_upload_file')
                        ->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                        ->disk('local')
                        ->directory('suppliers/csv/uploads')
                        ->maxSize((int) config('marketplace.suppliers.csv_max_upload_kb', 10240))
                        ->helperText('Stored in private local storage. Upload a new file to replace the current feed.')
                        ->visible(fn (Get $get): bool => $get('connector_type') === Supplier::CONNECTOR_CSV_UPLOAD)
                        ->columnSpanFull(),
                    Select::make('config.csv_delimiter')
                        ->label('Delimiter')
                        ->options([
                            'auto' => 'Auto-detect',
                            'comma' => 'Comma',
                            'semicolon' => 'Semicolon',
                            'tab' => 'Tab',
                            'pipe' => 'Pipe',
                        ])
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
                        ->default('UTF-8'),
                    TextInput::make('config.csv_enclosure')->label('Enclosure')->default('"'),
                    TextInput::make('config.csv_escape')->label('Escape')->default('\\'),
                    Toggle::make('config.csv_has_header')->label('First row is header')->default(true),
                    TextInput::make('config.csv_data_start_row')->label('Data start row')->numeric()->default(1),
                    TextInput::make('config.csv_sku_column')->label('SKU column')->required(),
                    TextInput::make('config.csv_stock_column')->label('Stock column'),
                    TextInput::make('config.csv_availability_column')->label('Availability column'),
                    TextInput::make('config.csv_barcode_column')->label('Barcode column'),
                    TextInput::make('config.csv_vendor_column')->label('Vendor column'),
                    TextInput::make('config.csv_title_column')->label('Title / name column'),
                    TextInput::make('config.csv_price_column')->label('Cost / price column'),
                ])
                ->columnSpanFull(),
            Section::make('XML settings')
                ->visible(fn (Get $get): bool => $get('connector_type') === Supplier::CONNECTOR_XML_URL)
                ->description('M-Tac uses built-in Google Atom paths when these are empty. Other XML suppliers require item + SKU paths.')
                ->columns(2)
                ->schema([
                    TextInput::make('config.xml_item_path')
                        ->label('Item XPath')
                        ->helperText('Example: //product or //atom:entry')
                        ->columnSpanFull(),
                    TextInput::make('config.xml_sku_path')->label('SKU path')->helperText('Relative to each item node'),
                    TextInput::make('config.xml_stock_path')->label('Stock path'),
                    TextInput::make('config.xml_availability_path')->label('Availability path'),
                    TextInput::make('config.xml_barcode_path')->label('Barcode path'),
                    TextInput::make('config.xml_title_path')->label('Title path'),
                    KeyValue::make('config.xml_namespaces')
                        ->label('XML namespaces')
                        ->keyLabel('Prefix')
                        ->valueLabel('URI')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Matching')
                ->visible(fn (Get $get): bool => in_array($get('connector_type'), [
                    Supplier::CONNECTOR_CSV_URL,
                    Supplier::CONNECTOR_CSV_UPLOAD,
                    Supplier::CONNECTOR_XML_URL,
                ], true))
                ->columns(2)
                ->schema([
                    Select::make('config.matching_strategy')
                        ->label('Matching strategy')
                        ->options([
                            'scoped_default' => 'Scoped default',
                            'sku_global' => 'Global SKU',
                        ])
                        ->default('scoped_default')
                        ->helperText('Global SKU ignores vendor and matches supplier SKU directly to Shopify variant SKU.')
                        ->columnSpanFull(),
                    Toggle::make('config.match_by_barcode')
                        ->label('Match by barcode')
                        ->helperText('When enabled, barcode matching is attempted before SKU matching.')
                        ->default(false),
                    TextInput::make('config.vendor_scope')
                        ->label('Vendor scope (comma-separated)')
                        ->helperText('Used by scoped matching only. Leave empty for Global SKU.')
                        ->formatStateUsing(fn (mixed $state): ?string => is_array($state) ? implode(', ', $state) : (is_string($state) ? $state : null))
                        ->dehydrateStateUsing(function (?string $state): array {
                            if (blank($state)) {
                                return [];
                            }

                            return array_values(array_filter(array_map(
                                fn (string $vendor): string => trim($vendor),
                                explode(',', $state),
                            )));
                        })
                        ->columnSpanFull(),
                    Toggle::make('config.require_vendor_scope')
                        ->label('Require vendor scope')
                        ->default(true)
                        ->helperText('Disable for Global SKU matching.'),
                    Select::make('config.missing_from_feed_policy')
                        ->label('Missing from feed policy')
                        ->options([
                            'mark_unavailable' => 'Mark missing rows unavailable',
                            'ignore' => 'Ignore missing rows',
                        ])
                        ->default('mark_unavailable')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            TextInput::make('stock_priority')->numeric()->default(100),
            TextInput::make('in_stock_delivery_text')->default('5-10 d.d.'),
            TextInput::make('backorder_delivery_text'),
            Toggle::make('allow_backorder_export')->default(false),
            TextInput::make('availability_fallback_quantity')
                ->numeric()
                ->default(5)
                ->helperText('Quantity used only when supplier reports positive boolean/text availability but no numeric stock quantity. Explicit numeric zero remains unavailable by default.'),
            Section::make('Daily sync')
                ->columns(2)
                ->schema([
                    Toggle::make('sync_enabled')->label('Sync enabled')->default(false),
                    Toggle::make('force_daily_sync')
                        ->label('Force every daily sync')
                        ->helperText('Ignore sync interval and always run during marketplace daily sync.'),
                    TextInput::make('sync_interval_minutes')
                        ->label('Sync interval minutes')
                        ->numeric()
                        ->helperText('Skipped during daily sync when last sync is newer than this interval, unless forced.'),
                    Toggle::make('config.block_daily_sync_on_failure')
                        ->label('Block daily sync on failure')
                        ->helperText('When enabled, a failed sync stops readiness refresh and Varle export.'),
                    TextInput::make('stale_after_minutes')->numeric()->label('Stale after minutes'),
                    TextInput::make('last_sync_at')
                        ->label('Last synced at')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (mixed $state): ?string => $state instanceof \Carbon\CarbonInterface
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
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('connector_type')->label('Connector'),
                IconColumn::make('enabled')->boolean(),
                IconColumn::make('sync_enabled')->boolean()->label('Sync'),
                TextColumn::make('in_stock_delivery_text')->label('Delivery'),
                TextColumn::make('last_sync_at')->dateTime()->sortable(),
                TextColumn::make('last_sync_status')->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                PreviewSupplierCsvAction::make(),
                Action::make('syncNow')
                    ->label('Sync now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            Notification::make()->title('Supplier code is missing')->danger()->send();

                            return;
                        }

                        $result = app(\App\Services\Sync\MarketplaceJobDispatcher::class)
                            ->dispatchSupplierSync((string) $record->code);

                        if ($result->alreadyRunning) {
                            Notification::make()
                                ->title('Job already running')
                                ->body($result->message)
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Supplier sync queued')
                            ->body($result->message ?? 'Job started in background.')
                            ->success()
                            ->send();
                    }),
                Action::make('dryRun')
                    ->label('Dry run')
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            return;
                        }

                        $result = app(\App\Services\Sync\MarketplaceJobDispatcher::class)
                            ->dispatchSupplierSync((string) $record->code, dryRun: true);

                        if ($result->alreadyRunning) {
                            Notification::make()
                                ->title('Job already running')
                                ->body($result->message)
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Dry run queued')
                            ->body($result->message ?? 'Job started in background.')
                            ->success()
                            ->send();
                    }),
                Action::make('testConnection')
                    ->label('Test connection')
                    ->visible(fn (Supplier $record): bool => in_array($record->connector_type, [
                        Supplier::CONNECTOR_XML_URL,
                        Supplier::CONNECTOR_API,
                        Supplier::CONNECTOR_CSV_URL,
                        Supplier::CONNECTOR_CSV_UPLOAD,
                    ], true))
                    ->action(function (Supplier $record): void {
                        $ok = app(SupplierConnectionTester::class)->test($record);
                        Notification::make()
                            ->title($ok ? 'Connection successful' : 'Connection failed')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
