<?php

namespace App\Filament\Resources\PiguSupplierXml;

use App\Filament\Resources\PiguSupplierXml\Pages\CreatePiguSupplierXml;
use App\Filament\Resources\PiguSupplierXml\Pages\EditPiguSupplierXml;
use App\Filament\Resources\PiguSupplierXml\Pages\ListPiguSupplierXml;
use App\Filament\Resources\PiguSupplierXml\Pages\PreviewCombinedPiguOffers;
use App\Filament\Resources\PiguSupplierXml\Pages\PreviewPiguSupplierOffers;
use App\Filament\Resources\PiguSupplierXml\Pages\ViewPiguSupplierXml;
use App\Filament\Resources\PiguSupplierXml\Schemas\PiguSupplierXmlWizardSteps;
use App\Filament\Resources\Suppliers\Actions\PreviewSupplierFeedAction;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierOfferStats;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Sync\MarketplaceJobDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PiguSupplierXmlResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Pigu';

    protected static ?string $navigationLabel = 'Tiekėjų XML';

    protected static ?string $modelLabel = 'Pigu tiekėjo XML';

    protected static ?string $pluralModelLabel = 'Pigu tiekėjų XML';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'pigu-tiekeju-xml';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('connector_type', Supplier::CONNECTOR_XML_URL);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make(PiguSupplierXmlWizardSteps::steps())->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tiekėjas')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Produktai')
                    ->counts('supplierProducts')
                    ->sortable(),
                TextColumn::make('valid_offers')
                    ->label('Galiojantys pasiūlymai')
                    ->getStateUsing(function (Supplier $record): int {
                        return app(PiguSupplierOfferStats::class)->reviewForSupplier($record)['valid_offers'];
                    }),
                TextColumn::make('last_sync_at')
                    ->label('Paskutinė sinchronizacija')
                    ->dateTime('Y-m-d H:i')
                    ->timezone('Europe/Vilnius')
                    ->placeholder('—'),
                TextColumn::make('pigu_status')
                    ->label('Būsena')
                    ->badge()
                    ->getStateUsing(function (Supplier $record): string {
                        if (! $record->enabled) {
                            return 'Išjungtas';
                        }
                        if (! PiguSupplierOfferConfig::isEnabled($record)) {
                            return 'Neaktyvuotas';
                        }
                        if ($record->last_sync_status === 'failed') {
                            return 'Klaida';
                        }

                        return 'Aktyvus';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktyvus' => 'success',
                        'Klaida' => 'danger',
                        'Išjungtas' => 'gray',
                        default => 'warning',
                    }),
                IconColumn::make('enabled')
                    ->label('Įjungtas')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()->label('Peržiūrėti'),
                EditAction::make()->label('Redaguoti'),
                Action::make('testConnection')
                    ->label('Tikrinti ryšį')
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(function (Supplier $record): void {
                        $ok = app(SupplierConnectionTester::class)->test($record);
                        Notification::make()
                            ->title($ok ? 'XML ryšys sėkmingas' : 'Nepavyko pasiekti XML')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
                Action::make('syncNow')
                    ->label('Sinchronizuoti dabar')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(function (Supplier $record, MarketplaceJobDispatcher $dispatcher): void {
                        $result = $dispatcher->dispatchSupplierSync($record->code);
                        Notification::make()
                            ->title($result->alreadyRunning ? 'Sinchronizacija jau vyksta' : 'Sinchronizacija įtraukta į eilę')
                            ->body($result->message)
                            ->{$result->alreadyRunning ? 'warning' : 'success'}()
                            ->send();
                    }),
                PreviewSupplierFeedAction::make()->label('Peržiūrėti produktus'),
                Action::make('disable')
                    ->label('Išjungti')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Supplier $record): bool => $record->enabled)
                    ->requiresConfirmation()
                    ->action(function (Supplier $record): void {
                        $config = $record->config ?? [];
                        $config[PiguSupplierOfferConfig::CONFIG_KEY] = array_merge(
                            PiguSupplierOfferConfig::defaults(),
                            is_array($config[PiguSupplierOfferConfig::CONFIG_KEY] ?? null)
                                ? $config[PiguSupplierOfferConfig::CONFIG_KEY]
                                : [],
                            ['enabled' => false],
                        );
                        $record->update([
                            'enabled' => false,
                            'sync_enabled' => false,
                            'config' => $config,
                        ]);
                        Notification::make()->title('Tiekėjas išjungtas')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPiguSupplierXml::route('/'),
            'create' => CreatePiguSupplierXml::route('/create'),
            'view' => ViewPiguSupplierXml::route('/{record}'),
            'preview' => PreviewPiguSupplierOffers::route('/{record}/pigu-xml-preview'),
            'previewCombined' => PreviewCombinedPiguOffers::route('/{record}/pigu-xml-combined-preview'),
            'edit' => EditPiguSupplierXml::route('/{record}/edit'),
        ];
    }
}
