<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class PreviewCombinedPiguOffers extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Bendra Pigu XML peržiūra';

    protected string $view = 'filament.resources.pigu-supplier-xml.pages.preview-combined-pigu-offers';

    /** @var array<string, mixed> */
    public array $previewStatus = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->refreshStatus();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bendra Pigu XML peržiūra: '.$this->supplier()->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    protected function getHeaderActions(): array
    {
        $busy = $this->isBusy();
        $canDownload = (bool) ($this->previewStatus['can_download'] ?? false);

        return [
            Action::make('generate')
                ->label('Generuoti bendrą XML peržiūrą')
                ->icon(Heroicon::OutlinedPlay)
                ->disabled($busy)
                ->action(function (): void {
                    $service = app(PiguSupplierOfferPreviewService::class);
                    $result = $service->dispatch(
                        $this->supplier(),
                        PiguExportOptions::PREVIEW_SCOPE_COMBINED,
                    );
                    $this->previewStatus = $service->status(
                        $this->supplier(),
                        PiguExportOptions::PREVIEW_SCOPE_COMBINED,
                    );

                    Notification::make()
                        ->title($result['message'])
                        ->{$result['dispatched'] ? 'success' : 'warning'}()
                        ->send();
                }),
            Action::make('downloadXml')
                ->label('Atsisiųsti bendrą XML')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->disabled(! $canDownload)
                ->url(fn (): string => route('exports.pigu-supplier-preview.combined.xml', $this->supplier()))
                ->openUrlInNewTab(),
            Action::make('downloadSkipped')
                ->label('Atsisiųsti atmestas prekes CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->disabled(! $canDownload)
                ->url(fn (): string => route('exports.pigu-supplier-preview.combined.skipped', $this->supplier()))
                ->openUrlInNewTab(),
            Action::make('back')
                ->label('Grįžti')
                ->url(PiguSupplierXmlResource::getUrl('view', ['record' => $this->supplier()]))
                ->color('gray'),
        ];
    }

    public function refreshStatus(): void
    {
        $this->previewStatus = app(PiguSupplierOfferPreviewService::class)->status(
            $this->supplier(),
            PiguExportOptions::PREVIEW_SCOPE_COMBINED,
        );
    }

    public function isBusy(): bool
    {
        return in_array($this->previewStatus['status'] ?? null, [
            PiguSupplierOfferPreviewStatus::STATUS_PENDING,
            PiguSupplierOfferPreviewStatus::STATUS_RUNNING,
        ], true);
    }

    private function supplier(): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $this->getRecord();

        return $supplier;
    }
}
