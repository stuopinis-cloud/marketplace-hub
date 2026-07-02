<?php

namespace App\Filament\Resources\MarketplaceChannels;

use App\Filament\Resources\MarketplaceChannels\Pages\CreateMarketplaceChannel;
use App\Filament\Resources\MarketplaceChannels\Pages\EditMarketplaceChannel;
use App\Filament\Resources\MarketplaceChannels\Pages\ListMarketplaceChannels;
use App\Filament\Resources\MarketplaceChannels\Schemas\MarketplaceChannelForm;
use App\Filament\Resources\MarketplaceChannels\Tables\MarketplaceChannelsTable;
use App\Models\MarketplaceChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MarketplaceChannelResource extends Resource
{
    protected static ?string $model = MarketplaceChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return MarketplaceChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketplaceChannelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceChannels::route('/'),
            'create' => CreateMarketplaceChannel::route('/create'),
            'edit' => EditMarketplaceChannel::route('/{record}/edit'),
        ];
    }
}
