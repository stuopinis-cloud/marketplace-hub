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

        // Preserve keys not present on the form (Filament replaces the whole JSON `config` blob).
        $data['config'] = array_merge(
            $this->record->config ?? [],
            is_array($data['config'] ?? null) ? $data['config'] : [],
        );

        $data = $this->canonicalizeCsvColumnMappings($data);

        $headersJson = data_get($data, 'config.request_headers_json');

        if (is_string($headersJson) && filled($headersJson)) {
            $decoded = json_decode($headersJson, true);

            if (is_array($decoded)) {
                $data['config']['request_headers'] = $decoded;
            }
        }

        unset($data['config']['request_headers_json']);

        $upload = $data['csv_upload_file'] ?? null;
        unset($data['csv_upload_file']);

        if (filled($upload)) {
            $path = is_array($upload) ? (string) reset($upload) : (string) $upload;
            $storedPath = $this->finalizeCsvUpload($path);
            $data['config']['uploaded_file_path'] = $storedPath;
            $data['config']['uploaded_file_name'] = basename($storedPath);
        }

        return $data;
    }

    /**
     * Promote any legacy nested mapping paths onto the canonical root-level config keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalizeCsvColumnMappings(array $data): array
    {
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        $canonicalKeys = [
            'csv_sku_column' => ['csv.sku_column', 'csv.columns.sku', 'column_mapping.sku'],
            'csv_barcode_column' => ['csv.barcode_column', 'csv.columns.barcode', 'column_mapping.barcode'],
            'csv_stock_column' => ['csv.stock_column', 'csv.columns.stock', 'column_mapping.stock'],
            'csv_availability_column' => ['csv.availability_column', 'csv.columns.availability', 'column_mapping.availability'],
            'csv_title_column' => ['csv.title_column', 'csv.columns.title', 'column_mapping.title'],
            'csv_price_column' => ['csv.price_column', 'csv.columns.price', 'column_mapping.price'],
            'csv_vendor_column' => ['csv.vendor_column', 'csv.columns.vendor', 'column_mapping.vendor'],
        ];

        foreach ($canonicalKeys as $canonical => $aliases) {
            if (filled($config[$canonical] ?? null)) {
                continue;
            }

            foreach ($aliases as $alias) {
                $value = data_get($config, $alias);

                if (filled($value)) {
                    $config[$canonical] = is_string($value) ? trim($value) : $value;
                    break;
                }
            }
        }

        $data['config'] = $config;

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
