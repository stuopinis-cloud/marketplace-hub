<?php

namespace App\Filament\Pages;

use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguSupplierOfferStats;
use App\Services\Sync\MarketplaceJobDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class PiguOffersOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Pasiūlymų apžvalga';

    protected static ?string $title = 'Pigu pasiūlymų apžvalga';

    protected static string|UnitEnum|null $navigationGroup = 'Pigu';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.pigu-offers-overview';

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        return app(PiguSupplierOfferStats::class)->overview();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function getSupplierRows()
    {
        return app(PiguSupplierOfferStats::class)->supplierList();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateOffers')
                ->label('Perkurti pasiūlymų XML')
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->requiresConfirmation()
                ->modalHeading('Perkurti pigu-offers.xml?')
                ->modalDescription('Bus sugeneruotas naujas all-valid pasiūlymų feedas ir atomiškai pakeistas viešas failas.')
                ->action(function (): void {
                    try {
                        $result = app(PiguOfferFeedExporter::class)->export(
                            new PiguExportOptions(allValid: true),
                        );
                        Notification::make()
                            ->title('Pasiūlymų XML atnaujintas')
                            ->body('Eksportuota: '.$result['offers_exported'].' (tik tiekėjo: '.($result['supplier_only_offers_count'] ?? 0).')')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Eksportas nepavyko')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('runDailySync')
                ->label('Paleisti dienos sinchronizaciją')
                ->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    $result = $dispatcher->dispatchDailySync();
                    Notification::make()
                        ->title($result->alreadyRunning ? 'Jau vyksta' : 'Dienos sinchronizacija įtraukta į eilę')
                        ->body($result->message)
                        ->{$result->alreadyRunning ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }

    public function formatTimestamp(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->timezone('Europe/Vilnius')->format('Y-m-d H:i');
        }

        return (string) $value;
    }
}
