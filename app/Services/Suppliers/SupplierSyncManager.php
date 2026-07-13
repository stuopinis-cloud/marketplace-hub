<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvSupplierImporter;
use App\Services\Suppliers\Helik\HelikSupplierImporter;
use App\Services\Suppliers\Mtac\MtacSupplierImporter;
use InvalidArgumentException;

class SupplierSyncManager
{
    public function __construct(
        private readonly MtacSupplierImporter $mtacImporter,
        private readonly HelikSupplierImporter $helikImporter,
        private readonly SupplierCsvSupplierImporter $csvImporter,
        private readonly SupplierProvisioner $supplierProvisioner,
    ) {}

    public function sync(string $supplierCode, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $supplierCode = mb_strtolower($supplierCode);

        if ($supplierCode === Supplier::CODE_MTAC) {
            $this->supplierProvisioner->ensureMtacSupplier();
        }

        if ($supplierCode === Supplier::CODE_HELIK) {
            $this->supplierProvisioner->ensureHelikSupplier();
        }

        $supplier = Supplier::query()->where('code', $supplierCode)->first();

        if (! $supplier instanceof Supplier) {
            throw new InvalidArgumentException('Unknown supplier code: '.$supplierCode);
        }

        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_XML_URL => $this->syncXmlUrlSupplier($supplier, $options),
            Supplier::CONNECTOR_API => $this->syncApiSupplier($supplier, $options),
            Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->csvImporter->sync($supplier, $options),
            default => throw new InvalidArgumentException('Unsupported supplier connector type: '.$supplier->connector_type),
        };
    }

    private function syncXmlUrlSupplier(Supplier $supplier, ?SupplierSyncOptions $options): SupplierSyncResult
    {
        if ($supplier->code !== Supplier::CODE_MTAC) {
            throw new InvalidArgumentException('Unsupported XML URL supplier: '.$supplier->code);
        }

        return $this->mtacImporter->sync($options);
    }

    private function syncApiSupplier(Supplier $supplier, ?SupplierSyncOptions $options): SupplierSyncResult
    {
        if ($supplier->code !== Supplier::CODE_HELIK) {
            throw new InvalidArgumentException('Unsupported API supplier: '.$supplier->code);
        }

        return $this->helikImporter->sync($options);
    }

    /**
     * @return array<int, array{code: string, name: string, result?: SupplierSyncResult, error?: string}>
     */
    public function syncEnabledSuppliers(?SupplierSyncOptions $options = null): array
    {
        $results = [];

        $suppliers = Supplier::query()
            ->where('enabled', true)
            ->where('sync_enabled', true)
            ->orderBy('stock_priority')
            ->get();

        foreach ($suppliers as $supplier) {
            if (blank($supplier->code)) {
                continue;
            }

            try {
                $result = $this->sync((string) $supplier->code, $options);
                $results[] = [
                    'code' => (string) $supplier->code,
                    'name' => (string) $supplier->name,
                    'result' => $result,
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'code' => (string) $supplier->code,
                    'name' => (string) $supplier->name,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function syncAll(?SupplierSyncOptions $options = null): array
    {
        return $this->syncEnabledSuppliers($options);
    }

    public function setupMtac(): Supplier
    {
        return $this->supplierProvisioner->ensureMtacSupplier();
    }

    public function setupHelik(): Supplier
    {
        return $this->supplierProvisioner->ensureHelikSupplier();
    }
}
