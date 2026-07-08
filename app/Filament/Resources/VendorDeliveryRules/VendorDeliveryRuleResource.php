<?php

namespace App\Filament\Resources\VendorDeliveryRules;

use App\Filament\Resources\VendorDeliveryRules\Pages\CreateVendorDeliveryRule;
use App\Filament\Resources\VendorDeliveryRules\Pages\EditVendorDeliveryRule;
use App\Filament\Resources\VendorDeliveryRules\Pages\ListVendorDeliveryRules;
use App\Models\Product;
use App\Models\VendorDeliveryRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

    protected static ?string $modelLabel = 'Vendor Delivery Rule';

    public static function canCreate(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vendor')
                ->required()
                ->searchable()
                ->options(fn (): array => self::vendorOptions())
                ->getSearchResultsUsing(fn (string $search): array => self::vendorSearchResults($search))
                ->getOptionLabelUsing(fn ($value): ?string => is_string($value) ? $value : null)
                ->helperText('Pick an existing product vendor or type a new name. Use * for the default fallback rule.'),
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
            ->defaultSort('priority')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function vendorOptions(): array
    {
        $options = Product::query()
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor', 'vendor')
            ->all();

        return [
            VendorDeliveryRule::DEFAULT_VENDOR => 'Default (* fallback)',
            ...$options,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function vendorSearchResults(string $search): array
    {
        $search = trim($search);

        $options = Product::query()
            ->whereNotNull('vendor')
            ->where('vendor', 'ilike', '%'.$search.'%')
            ->distinct()
            ->orderBy('vendor')
            ->limit(50)
            ->pluck('vendor', 'vendor')
            ->all();

        if ($search !== '' && ! isset($options[$search]) && $search !== VendorDeliveryRule::DEFAULT_VENDOR) {
            $options = [$search => $search] + $options;
        }

        if ($search === '*' || $search === '') {
            $options = [VendorDeliveryRule::DEFAULT_VENDOR => 'Default (* fallback)'] + $options;
        }

        return $options;
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
