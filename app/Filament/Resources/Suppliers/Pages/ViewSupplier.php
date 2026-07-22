<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Suppliers\SupplierDryRunService;
use App\Services\Suppliers\SupplierFeedPreviewService;
use App\Services\Sync\MarketplaceJobDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Overview')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code'),
                        TextEntry::make('connector_type')->badge(),
                        TextEntry::make('enabled')->label('Active')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('sync_enabled')->label('Sync enabled')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('auth_type')->label('Authentication'),
                        TextEntry::make('endpoint_url')->label('Endpoint URL')->columnSpanFull()->limit(80),
                    ]),
                Section::make('Matching')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('config.matching_strategy')->label('Strategy')->default('sku_global'),
                        TextEntry::make('config.match_by_barcode')->label('Match by barcode')->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
                        TextEntry::make('config.require_vendor_scope')->label('Require vendor scope')->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
                        TextEntry::make('config.vendor_scope')->label('Vendor scope')->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== [] ? implode(', ', $state) : '—')->columnSpanFull(),
                    ]),
                Section::make('Sync status')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('sync_interval_minutes')->label('Interval (minutes)')->placeholder('—'),
                        TextEntry::make('force_daily_sync')->label('Forced daily')->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
                        TextEntry::make('last_sync_at')->label('Last synced at')->dateTime()->placeholder('Never'),
                        TextEntry::make('last_sync_status')->label('Last status')->badge()->placeholder('—'),
                        TextEntry::make('last_sync_message')->label('Last message')->columnSpanFull()->placeholder('—'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('previewFeed')
                ->label('Preview feed')
                ->icon(Heroicon::OutlinedEye)
                ->modalHeading('Feed preview')
                ->modalWidth('5xl')
                ->modalContent(fn (): View => view(
                    'filament.resources.suppliers.feed-preview',
                    $this->buildFeedPreviewData(),
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('dryRun')
                ->label('Dry run')
                ->icon(Heroicon::OutlinedBeaker)
                ->modalHeading('Dry run results')
                ->modalWidth('5xl')
                ->modalContent(fn (): View => view(
                    'filament.resources.suppliers.dry-run-result',
                    app(SupplierDryRunService::class)->run($this->getSupplierRecord()),
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->action(function (): void {
                    $ok = app(SupplierConnectionTester::class)->test($this->getSupplierRecord());

                    Notification::make()
                        ->title($ok ? 'Connection successful' : 'Connection failed')
                        ->{$ok ? 'success' : 'danger'}()
                        ->send();
                }),
            Action::make('syncNow')
                ->label('Sync now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (): void {
                    $record = $this->getSupplierRecord();

                    if (blank($record->code)) {
                        Notification::make()->title('Supplier code is missing')->danger()->send();

                        return;
                    }

                    $result = app(MarketplaceJobDispatcher::class)->dispatchSupplierSync((string) $record->code);

                    if ($result->alreadyRunning) {
                        Notification::make()->title('Job already running')->body($result->message)->warning()->send();

                        return;
                    }

                    Notification::make()->title('Supplier sync queued')->body($result->message ?? 'Job started in background.')->success()->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFeedPreviewData(): array
    {
        try {
            return app(SupplierFeedPreviewService::class)->preview($this->getSupplierRecord());
        } catch (\Throwable $exception) {
            return [
                'error' => $exception->getMessage(),
                'type' => $this->getSupplierRecord()->connector_type,
            ];
        }
    }

    private function getSupplierRecord(): Supplier
    {
        /** @var Supplier $record */
        $record = $this->getRecord();

        return $record;
    }
}
