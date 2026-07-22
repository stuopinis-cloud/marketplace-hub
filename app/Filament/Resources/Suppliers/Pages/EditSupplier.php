<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\Concerns\MutatesSupplierFormData;
use App\Filament\Resources\Suppliers\Schemas\SupplierWizardSteps;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Suppliers\SupplierDryRunService;
use App\Services\Suppliers\SupplierFeedPreviewService;
use App\Services\Suppliers\SupplierOnboardingValidator;
use App\Services\Sync\MarketplaceJobDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class EditSupplier extends EditRecord
{
    use HasWizard;
    use MutatesSupplierFormData;

    protected static string $resource = SupplierResource::class;

    /**
     * @return array<int, \Filament\Schemas\Components\Wizard\Step>
     */
    public function getSteps(): array
    {
        return SupplierWizardSteps::steps(fn () => $this);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('previewFeed')
                ->label('Preview feed')
                ->icon(Heroicon::OutlinedEye)
                ->modalHeading('Feed preview')
                ->modalWidth('5xl')
                ->modalContent(function (): View {
                    return view(
                        'filament.resources.suppliers.feed-preview',
                        $this->buildFeedPreviewData(),
                    );
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('dryRun')
                ->label('Dry run')
                ->icon(Heroicon::OutlinedBeaker)
                ->modalHeading('Dry run results')
                ->modalWidth('5xl')
                ->modalContent(function (): View {
                    return view(
                        'filament.resources.suppliers.dry-run-result',
                        app(SupplierDryRunService::class)->run($this->getSupplierRecord()),
                    );
                })
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
                    $this->runSupplierSync(dryRun: false);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getSupplierRecord();

        $wasSyncEnabled = (bool) $record->sync_enabled;
        $data = $this->mutateSupplierFormData($data, $record);

        // Only re-validate when sync is being turned on for the first time; once
        // enabled, unrelated edits (e.g. delivery text) should not be blocked by
        // credential/mapping checks that are unrelated to the fields being changed.
        if (($data['sync_enabled'] ?? false) && ! $wasSyncEnabled) {
            $transient = (clone $record);
            $transient->forceFill($data);

            $errors = app(SupplierOnboardingValidator::class)->validateForSync($transient);

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'sync_enabled' => $errors,
                ]);
            }
        }

        return $data;
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

    private function runSupplierSync(bool $dryRun): void
    {
        $record = $this->getSupplierRecord();

        if (blank($record->code)) {
            Notification::make()->title('Supplier code is missing')->danger()->send();

            return;
        }

        $result = app(MarketplaceJobDispatcher::class)->dispatchSupplierSync(
            (string) $record->code,
            $dryRun,
        );

        if ($result->alreadyRunning) {
            Notification::make()
                ->title('Job already running')
                ->body($result->message)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($dryRun ? 'Dry run queued' : 'Supplier sync queued')
            ->body($result->message ?? 'Job started in background.')
            ->success()
            ->send();
    }

    private function getSupplierRecord(): Supplier
    {
        /** @var Supplier $record */
        $record = $this->getRecord();

        return $record;
    }
}
