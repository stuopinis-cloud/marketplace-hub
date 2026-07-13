<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
                ]),
            TextInput::make('endpoint_url')->columnSpanFull(),
            Select::make('auth_type')
                ->options([
                    Supplier::AUTH_NONE => 'None',
                    Supplier::AUTH_BEARER_TOKEN => 'Bearer token',
                ]),
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
                ->helperText('Stored encrypted. Leave blank to keep the existing token or use ENTIREM_API_TOKEN from the environment.'),
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
            KeyValue::make('config')
                ->helperText('For API suppliers, configure request_body Items/Categories and response_data_path.')
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
                    ->action(function (Supplier $record): void {
                        if (blank($record->endpoint_url)) {
                            Notification::make()->title('Endpoint URL missing')->danger()->send();

                            return;
                        }

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
