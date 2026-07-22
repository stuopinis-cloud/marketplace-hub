<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\Concerns\MutatesSupplierFormData;
use App\Filament\Resources\Suppliers\Schemas\SupplierWizardSteps;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierOnboardingValidator;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Validation\ValidationException;

class CreateSupplier extends CreateRecord
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->mutateSupplierFormData($data, null);

        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['sync_enabled'] = (bool) ($data['sync_enabled'] ?? false);
        $data['auth_type'] = $data['auth_type'] ?? Supplier::AUTH_NONE;
        $data['availability_fallback_quantity'] = $data['availability_fallback_quantity'] ?? 5;

        if ($data['sync_enabled']) {
            $this->assertSyncReady($data);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Supplier) {
            $this->finalizePendingCsvUpload($record);
        }
    }

    /**
     * Validate the form state against the same rules used before enabling sync on
     * an existing supplier, using the not-yet-persisted CSV upload's temporary path.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSyncReady(array $data): void
    {
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        if (blank($config['uploaded_file_path'] ?? null) && filled($config['pending_csv_upload_tmp_path'] ?? null)) {
            $config['uploaded_file_path'] = $config['pending_csv_upload_tmp_path'];
        }

        $transient = new Supplier;
        $transient->forceFill([
            ...$data,
            'config' => $config,
        ]);

        $errors = app(SupplierOnboardingValidator::class)->validateForSync($transient);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'sync_enabled' => $errors,
            ]);
        }
    }
}
