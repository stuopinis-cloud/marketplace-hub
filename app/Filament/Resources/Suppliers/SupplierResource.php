<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Actions\PreviewSupplierCsvAction;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
            Toggle::make('enabled')->default(true),
            Select::make('connector_type')
                ->options([
                    Supplier::CONNECTOR_XML_URL => 'XML URL',
                    Supplier::CONNECTOR_API => 'API',
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
                ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BASIC),
            TextInput::make('credential_password')
                ->label('Password')
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->afterStateHydrated(function (TextInput $component, ?Supplier $record): void {
                    $component->state(filled(data_get($record?->credentials, 'password')) ? '********' : null);
                })
                ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BASIC),
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
                            'comma' => 'Comma',
                            'semicolon' => 'Semicolon',
                            'tab' => 'Tab',
                            'pipe' => 'Pipe',
                        ])
                        ->default('comma'),
                    Select::make('config.csv_encoding')
                        ->label('Encoding')
                        ->options([
                            'UTF-8' => 'UTF-8',
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
                    TextInput::make('config.csv_title_column')->label('Title column'),
                    TextInput::make('config.csv_price_column')->label('Price column'),
                    TextInput::make('config.vendor_scope')
                        ->label('Vendor scope')
                        ->helperText('Comma-separated Shopify vendor names used for SKU matching.')
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
            Toggle::make('sync_enabled')->default(false),
            TextInput::make('sync_interval_minutes')->numeric(),
            TextInput::make('stale_after_minutes')->numeric(),
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

                        try {
                            app(SupplierSyncManager::class)->sync((string) $record->code);
                            Notification::make()->title('Supplier sync finished')->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Supplier sync failed')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('dryRun')
                    ->label('Dry run')
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            return;
                        }

                        try {
                            app(SupplierSyncManager::class)->sync((string) $record->code, new SupplierSyncOptions(dryRun: true));
                            Notification::make()->title('Dry run finished')->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Dry run failed')->body($exception->getMessage())->danger()->send();
                        }
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
