<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class PreviewPiguSupplierOffers extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Pigu XML peržiūra';

    protected string $view = 'filament.resources.pigu-supplier-xml.pages.preview-pigu-supplier-offers';

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->generatePreview();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Pigu XML peržiūra: '.$this->supplier()->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Atnaujinti peržiūrą')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $this->generatePreview();
                    Notification::make()
                        ->title('Peržiūra atnaujinta')
                        ->success()
                        ->send();
                }),
            Action::make('downloadXml')
                ->label('Atsisiųsti XML')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('exports.pigu-supplier-preview.xml', $this->supplier()))
                ->openUrlInNewTab(),
            Action::make('downloadSkipped')
                ->label('Atsisiųsti atmestas prekes CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('exports.pigu-supplier-preview.skipped', $this->supplier()))
                ->openUrlInNewTab(),
            Action::make('back')
                ->label('Grįžti')
                ->url(PiguSupplierXmlResource::getUrl('view', ['record' => $this->supplier()]))
                ->color('gray'),
        ];
    }

    public function generatePreview(): void
    {
        $supplier = $this->supplier();
        $enabledBefore = PiguSupplierOfferConfig::isEnabled($supplier);

        $this->preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);

        $supplier->refresh();

        if (PiguSupplierOfferConfig::isEnabled($supplier) !== $enabledBefore) {
            Notification::make()
                ->title('Klaida: peržiūra pakeitė tiekėjo būseną')
                ->danger()
                ->send();
        }
    }

    private function supplier(): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $this->getRecord();

        return $supplier;
    }
}
