<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\Actions\PreviewSupplierCsvAction;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PreviewSupplierCsvAction::make(),
            Action::make('dryRun')
                ->label('Dry run')
                ->icon(Heroicon::OutlinedBeaker)
                ->visible(fn (): bool => $this->isCsvSupplier())
                ->action(function (): void {
                    $this->runSupplierSync(dryRun: true);
                }),
            Action::make('syncNow')
                ->label('Sync now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->isCsvSupplier())
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
        $token = $data['credential_token'] ?? null;

        if (filled($token) && $token !== '********') {
            $data['credentials'] = array_merge($this->record->credentials ?? [], [
                'token' => $token,
            ]);
        }

        $username = $data['credential_username'] ?? null;

        if (filled($username)) {
            $data['credentials'] = array_merge($data['credentials'] ?? $this->record->credentials ?? [], [
                'username' => $username,
            ]);
        }

        $password = $data['credential_password'] ?? null;

        if (filled($password) && $password !== '********') {
            $data['credentials'] = array_merge($data['credentials'] ?? $this->record->credentials ?? [], [
                'password' => $password,
            ]);
        }

        unset($data['credential_token'], $data['credential_username'], $data['credential_password']);

        $headersJson = data_get($data, 'config.request_headers_json');

        if (is_string($headersJson) && filled($headersJson)) {
            $decoded = json_decode($headersJson, true);

            if (is_array($decoded)) {
                $data['config'] = array_merge($data['config'] ?? $this->record->config ?? [], [
                    'request_headers' => $decoded,
                ]);
            }
        }

        unset($data['config']['request_headers_json']);

        $upload = $data['csv_upload_file'] ?? null;
        unset($data['csv_upload_file']);

        if (filled($upload)) {
            $path = is_array($upload) ? (string) reset($upload) : (string) $upload;
            $storedPath = $this->finalizeCsvUpload($path);
            $config = $data['config'] ?? $this->record->config ?? [];
            $config['uploaded_file_path'] = $storedPath;
            $config['uploaded_file_name'] = basename($storedPath);
            $data['config'] = $config;
        }

        return $data;
    }

    private function finalizeCsvUpload(string $temporaryPath): string
    {
        $disk = Storage::disk('local');
        $targetDirectory = 'suppliers/csv/'.$this->record->getKey();
        $filename = basename($temporaryPath);
        $targetPath = $targetDirectory.'/'.$filename;

        if ($disk->exists($temporaryPath)) {
            $disk->makeDirectory($targetDirectory);
            $disk->move($temporaryPath, $targetPath);
        }

        return $targetPath;
    }

    private function isCsvSupplier(): bool
    {
        $record = $this->getRecord();

        return $record instanceof Supplier
            && in_array($record->connector_type, [Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD], true);
    }

    private function runSupplierSync(bool $dryRun): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Supplier || blank($record->code)) {
            return;
        }

        $result = app(\App\Services\Sync\MarketplaceJobDispatcher::class)->dispatchSupplierSync(
            (string) $record->code,
            $dryRun,
        );

        if ($result->alreadyRunning) {
            \Filament\Notifications\Notification::make()
                ->title('Job already running')
                ->body($result->message)
                ->warning()
                ->send();

            return;
        }

        \Filament\Notifications\Notification::make()
            ->title($dryRun ? 'Dry run queued' : 'Supplier sync queued')
            ->body($result->message ?? 'Job started in background.')
            ->success()
            ->send();
    }
}
