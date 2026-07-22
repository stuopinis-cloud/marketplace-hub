<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use App\Services\Suppliers\Json\SupplierJsonConfig;
use App\Services\Suppliers\Xml\SupplierXmlConfig;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierOnboardingValidator
{
    /**
     * @return array<int, string>
     */
    public function validateForSync(Supplier $supplier): array
    {
        $errors = [];

        if (blank($supplier->code)) {
            $errors[] = 'Supplier code is required.';
        }

        if (blank($supplier->connector_type)) {
            $errors[] = 'Connector type is required.';
        }

        if (in_array($supplier->connector_type, [
            Supplier::CONNECTOR_CSV_URL,
            Supplier::CONNECTOR_XML_URL,
            Supplier::CONNECTOR_JSON_API,
            Supplier::CONNECTOR_API,
            Supplier::CONNECTOR_MTAC,
            Supplier::CONNECTOR_HELIK_API,
        ], true) && blank($supplier->endpoint_url)) {
            $errors[] = 'Endpoint URL is required for this connector.';
        }

        if ($supplier->connector_type === Supplier::CONNECTOR_CSV_UPLOAD) {
            $path = SupplierCsvConfig::uploadedFilePath($supplier);

            if (blank($path) || ! Storage::disk('local')->exists((string) $path)) {
                $errors[] = 'An uploaded CSV file is required.';
            }
        }

        $credentialErrors = $this->credentialErrors($supplier);
        $errors = [...$errors, ...$credentialErrors];

        $errors = [...$errors, ...match ($supplier->connector_type) {
            Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->csvMappingErrors($supplier),
            Supplier::CONNECTOR_XML_URL => $this->xmlMappingErrors($supplier),
            Supplier::CONNECTOR_JSON_API, Supplier::CONNECTOR_API => $this->jsonMappingErrors($supplier),
            Supplier::CONNECTOR_MTAC, Supplier::CONNECTOR_HELIK_API => [],
            default => ['Unsupported connector type.'],
        }];

        return array_values(array_unique($errors));
    }

    public function assertCanEnableSync(Supplier $supplier): void
    {
        $errors = $this->validateForSync($supplier);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'sync_enabled' => $errors,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function credentialErrors(Supplier $supplier): array
    {
        $resolver = app(SupplierCredentialResolver::class);

        return match ($supplier->auth_type) {
            Supplier::AUTH_BEARER_TOKEN => $resolver->hasBearerToken($supplier)
                ? []
                : ['Bearer token credentials are required.'],
            Supplier::AUTH_BASIC, Supplier::AUTH_NTLM => $resolver->hasUsernamePassword($supplier)
                ? []
                : ['Username and password credentials are required.'],
            Supplier::AUTH_CUSTOM_HEADERS => filled(data_get($supplier->config, 'request_headers'))
                ? []
                : ['Custom request headers are required.'],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function csvMappingErrors(Supplier $supplier): array
    {
        $errors = [];

        if (SupplierCsvConfig::skuColumn($supplier) === null) {
            $errors[] = 'CSV SKU column mapping is required.';
        }

        if (SupplierCsvConfig::stockColumn($supplier) === null && SupplierCsvConfig::availabilityColumn($supplier) === null) {
            $errors[] = 'CSV stock or availability column mapping is required.';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function xmlMappingErrors(Supplier $supplier): array
    {
        if ($supplier->code === Supplier::CODE_MTAC && ! SupplierXmlConfig::isConfigured($supplier)) {
            return [];
        }

        $errors = [];

        if (SupplierXmlConfig::itemPath($supplier) === null) {
            $errors[] = 'XML item path is required.';
        }

        if (SupplierXmlConfig::skuPath($supplier) === null) {
            $errors[] = 'XML SKU path is required.';
        }

        if (SupplierXmlConfig::stockPath($supplier) === null && SupplierXmlConfig::availabilityPath($supplier) === null) {
            $errors[] = 'XML stock or availability path is required.';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function jsonMappingErrors(Supplier $supplier): array
    {
        if ($supplier->code === Supplier::CODE_HELIK && $supplier->connector_type === Supplier::CONNECTOR_API) {
            return [];
        }

        if ($supplier->connector_type === Supplier::CONNECTOR_HELIK_API) {
            return [];
        }

        $errors = [];

        if (SupplierJsonConfig::dataPath($supplier) === null) {
            $errors[] = 'JSON data path is required.';
        }

        if (SupplierJsonConfig::skuPath($supplier) === null) {
            $errors[] = 'JSON SKU path is required.';
        }

        if (SupplierJsonConfig::stockPath($supplier) === null && SupplierJsonConfig::availabilityPath($supplier) === null) {
            $errors[] = 'JSON stock or availability path is required.';
        }

        return $errors;
    }
}
