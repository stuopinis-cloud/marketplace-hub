<?php

namespace App\Filament\Resources\Suppliers\Concerns;

use App\Models\Supplier;
use Illuminate\Support\Facades\Storage;

/**
 * Shared Filament form-data mutation logic for the Supplier wizard.
 *
 * Handles credential merging, config merging/canonicalization, JSON helper
 * fields, and CSV upload staging so that Create and Edit pages behave
 * identically. Works with or without an existing record: pass the current
 * record (Edit) or `null` (Create, before the row has an id).
 */
trait MutatesSupplierFormData
{
    /**
     * Apply all shared mutations to Filament form state before it is persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateSupplierFormData(array $data, ?Supplier $record): array
    {
        $data = $this->applySupplierCredentials($data, $record);
        $data = $this->mergeSupplierConfig($data, $record);
        $data = $this->canonicalizeCsvColumnMappings($data);
        $data = $this->applyRequestHeadersJson($data);
        $data = $this->applyRequestBodyJson($data);
        $data = $this->stageCsvUpload($data, $record);

        return $data;
    }

    /**
     * Merge the virtual credential_* fields into the encrypted `credentials` column.
     * Values of '********' mean "unchanged" and are ignored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applySupplierCredentials(array $data, ?Supplier $record): array
    {
        $credentials = $data['credentials'] ?? $record?->credentials ?? [];

        $token = $data['credential_token'] ?? null;

        if (filled($token) && $token !== '********') {
            $credentials['token'] = $token;
        }

        $username = $data['credential_username'] ?? null;

        if (filled($username)) {
            $credentials['username'] = $username;
        }

        $password = $data['credential_password'] ?? null;

        if (filled($password) && $password !== '********') {
            $credentials['password'] = $password;
        }

        $data['credentials'] = $credentials;

        unset($data['credential_token'], $data['credential_username'], $data['credential_password']);

        return $data;
    }

    /**
     * Preserve config keys not present on the current form state. Filament replaces
     * the whole JSON `config` blob with only the keys mounted on the form, so any
     * keys owned by other wizard steps (or written by importers) must be merged back in.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mergeSupplierConfig(array $data, ?Supplier $record): array
    {
        $data['config'] = array_merge(
            $record?->config ?? [],
            is_array($data['config'] ?? null) ? $data['config'] : [],
        );

        return $data;
    }

    /**
     * Promote any legacy nested mapping paths onto the canonical root-level config keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function canonicalizeCsvColumnMappings(array $data): array
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

    /**
     * Decode the custom request headers JSON helper field into `config.request_headers`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyRequestHeadersJson(array $data): array
    {
        $headersJson = data_get($data, 'config.request_headers_json');

        if (is_string($headersJson) && filled($headersJson)) {
            $decoded = json_decode($headersJson, true);

            if (is_array($decoded)) {
                $data['config']['request_headers'] = $decoded;
            }
        }

        unset($data['config']['request_headers_json']);

        return $data;
    }

    /**
     * Decode the request body JSON helper field into `config.request_body`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyRequestBodyJson(array $data): array
    {
        $bodyJson = data_get($data, 'config.request_body_json');

        if (is_string($bodyJson) && filled($bodyJson)) {
            $decoded = json_decode($bodyJson, true);

            if (is_array($decoded)) {
                $data['config']['request_body'] = $decoded;
            }
        }

        unset($data['config']['request_body_json']);

        return $data;
    }

    /**
     * Stage the uploaded CSV file. When a record already exists (Edit), the file is
     * moved into its permanent directory immediately. When creating a new supplier,
     * the temporary path is stashed on the config so {@see finalizePendingCsvUpload()}
     * can move it once the record has an id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stageCsvUpload(array $data, ?Supplier $record): array
    {
        $upload = $data['csv_upload_file'] ?? null;
        unset($data['csv_upload_file']);

        if (blank($upload)) {
            return $data;
        }

        $temporaryPath = is_array($upload) ? (string) reset($upload) : (string) $upload;

        if ($record instanceof Supplier && $record->exists) {
            $storedPath = $this->finalizeCsvUploadPath($record, $temporaryPath);
            $data['config']['uploaded_file_path'] = $storedPath;
            $data['config']['uploaded_file_name'] = basename($storedPath);

            return $data;
        }

        $data['config']['pending_csv_upload_tmp_path'] = $temporaryPath;

        return $data;
    }

    /**
     * Move a previously staged CSV upload into its permanent location once the
     * supplier record has an id. Safe to call unconditionally after a create.
     */
    protected function finalizePendingCsvUpload(Supplier $record): void
    {
        $temporaryPath = data_get($record->config, 'pending_csv_upload_tmp_path');

        if (blank($temporaryPath)) {
            return;
        }

        $storedPath = $this->finalizeCsvUploadPath($record, (string) $temporaryPath);

        $config = $record->config ?? [];
        unset($config['pending_csv_upload_tmp_path']);
        $config['uploaded_file_path'] = $storedPath;
        $config['uploaded_file_name'] = basename($storedPath);

        $record->update(['config' => $config]);
    }

    private function finalizeCsvUploadPath(Supplier $record, string $temporaryPath): string
    {
        $disk = Storage::disk('local');
        $targetDirectory = 'suppliers/csv/'.$record->getKey();
        $filename = basename($temporaryPath);
        $targetPath = $targetDirectory.'/'.$filename;

        if ($disk->exists($temporaryPath)) {
            $disk->makeDirectory($targetDirectory);
            $disk->move($temporaryPath, $targetPath);
        }

        return $targetPath;
    }
}
