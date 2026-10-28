<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierOfferStats;
use App\Services\Sync\MarketplaceJobDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewPiguSupplierXml extends ViewRecord
{
    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Pigu tiekėjo XML';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewPiguXml')
                ->label('Peržiūrėti Pigu XML')
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (): string => PiguSupplierXmlResource::getUrl('preview', ['record' => $this->getRecord()])),
            EditAction::make()->label('Redaguoti'),
            Action::make('syncNow')
                ->label('Sinchronizuoti dabar')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    /** @var Supplier $record */
                    $record = $this->getRecord();
                    $result = $dispatcher->dispatchSupplierSync($record->code);
                    Notification::make()
                        ->title($result->alreadyRunning ? 'Sinchronizacija jau vyksta' : 'Sinchronizacija įtraukta į eilę')
                        ->body($result->message)
                        ->{$result->alreadyRunning ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var Supplier $record */
        $record = $this->getRecord();
        $stats = app(PiguSupplierOfferStats::class)->reviewForSupplier($record);

        return $schema->components([
            Section::make('Tiekėjas')->schema([
                TextEntry::make('name')->label('Pavadinimas'),
                TextEntry::make('endpoint_url')->label('XML adresas'),
                TextEntry::make('enabled')->label('Įjungtas')->formatStateUsing(fn ($state): string => $state ? 'Taip' : 'Ne'),
                TextEntry::make('pigu_enabled')
                    ->label('Pigu pasiūlymai')
                    ->state(PiguSupplierOfferConfig::isEnabled($record) ? 'Aktyvuota' : 'Neaktyvuota'),
                TextEntry::make('last_sync_at')
                    ->label('Paskutinė sinchronizacija')
                    ->dateTime('Y-m-d H:i')
                    ->timezone('Europe/Vilnius')
                    ->placeholder('—'),
                TextEntry::make('last_sync_status')->label('Sinchronizacijos būsena')->placeholder('—'),
                TextEntry::make('last_sync_message')->label('Paskutinė žinutė')->placeholder('—')->columnSpanFull(),
            ])->columns(2),
            Section::make('Pasiūlymų diagnostika')->schema([
                TextEntry::make('total_products')->label('Iš viso produktų')->state((string) $stats['total_products']),
                TextEntry::make('valid_offers')->label('Galiojantys pasiūlymai')->state((string) $stats['valid_offers']),
                TextEntry::make('missing_ean')->label('Trūksta EAN')->state((string) $stats['missing_ean']),
                TextEntry::make('missing_sku')->label('Trūksta SKU')->state((string) $stats['missing_sku']),
                TextEntry::make('invalid_prices')->label('Netinkama kaina')->state((string) $stats['invalid_prices']),
                TextEntry::make('out_of_stock')->label('Nėra likučio')->state((string) $stats['out_of_stock']),
                TextEntry::make('in_shopify')->label('Jau Shopify')->state((string) $stats['in_shopify']),
                TextEntry::make('supplier_only')->label('Tik tiekėjas')->state((string) $stats['supplier_only']),
                TextEntry::make('ambiguous')->label('Konfliktiniai')->state((string) $stats['ambiguous']),
                TextEntry::make('estimated')->label('Numatomi papildomi pasiūlymai')->state((string) $stats['estimated_additional_offers']),
            ])->columns(2),
            Section::make('Išplėstiniai nustatymai')
                ->collapsed()
                ->schema([
                    TextEntry::make('code')->label('Sisteminis kodas'),
                    TextEntry::make('collection_hours')
                        ->label('collectionhours')
                        ->state((string) PiguSupplierOfferConfig::collectionHours($record)),
                    TextEntry::make('vat_rate')
                        ->label('PVM %')
                        ->state((string) PiguSupplierOfferConfig::vatRate($record)),
                    TextEntry::make('price_includes_vat')
                        ->label('Kaina su PVM')
                        ->state(PiguSupplierOfferConfig::priceIncludesVat($record) ? 'Taip' : 'Ne'),
                    TextEntry::make('stale_after_minutes')->label('Pasenimo minutės'),
                    TextEntry::make('config.xml_sku_path')->label('SKU kelias'),
                    TextEntry::make('config.xml_barcode_path')->label('EAN kelias'),
                    TextEntry::make('config.xml_stock_path')->label('Likučio kelias'),
                    TextEntry::make('config.xml_price_path')->label('Kainos kelias'),
                ])->columns(2),
        ]);
    }
}
