<?php

namespace App\Filament\Resources\VendorDeliveryRules;

use App\Filament\Resources\VendorDeliveryRules\Pages\CreateVendorDeliveryRule;
use App\Filament\Resources\VendorDeliveryRules\Pages\EditVendorDeliveryRule;
use App\Filament\Resources\VendorDeliveryRules\Pages\ListVendorDeliveryRules;
use App\Models\VendorDeliveryRule;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VendorDeliveryRuleResource extends Resource
{
    protected static ?string $model = VendorDeliveryRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Vendor Delivery Rules';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('vendor')
                ->required()
                ->maxLength(255)
                ->helperText('Use * for the default fallback rule.'),
            Toggle::make('enabled')->default(true),
            TextInput::make('in_stock_delivery_text')->default('1-2 d.d.')->required(),
            TextInput::make('backorder_delivery_text')->default('5-10 d.d.')->required(),
            Toggle::make('allow_backorder_export')->default(true),
            TextInput::make('priority')->numeric()->default(100)->required(),
            Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor')->searchable()->sortable(),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('in_stock_delivery_text')->label('In stock'),
                TextColumn::make('backorder_delivery_text')->label('Backorder'),
                IconColumn::make('allow_backorder_export')->boolean()->label('Backorders'),
                TextColumn::make('priority')->sortable(),
            ])
            ->defaultSort('priority');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorDeliveryRules::route('/'),
            'create' => CreateVendorDeliveryRule::route('/create'),
            'edit' => EditVendorDeliveryRule::route('/{record}/edit'),
        ];
    }
}
