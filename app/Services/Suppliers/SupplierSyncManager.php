<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Mtac\MtacSupplierImporter;
use App\Services\Suppliers\Mtac\MtacSupplierSyncOptions;
use App\Services\Suppliers\Mtac\MtacSupplierSyncResult;
use InvalidArgumentException;

class SupplierSyncManager
{
    public function __construct(
        private readonly MtacSupplierImporter $mtacImporter,
        private readonly SupplierProvisioner $supplierProvisioner,
    ) {}

    public function sync(string $supplierCode, ?MtacSupplierSyncOptions $options = null): MtacSupplierSyncResult
    {
        return match (mb_strtolower($supplierCode)) {
            Supplier::CODE_MTAC => $this->mtacImporter->sync($options),
            default => throw new InvalidArgumentException('Unsupported supplier code: '.$supplierCode),
        };
    }

    /**
     * @return array<int, array{code: string, name: string, result?: MtacSupplierSyncResult, error?: string}>
     */
    public function syncEnabledSuppliers(): array
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
                $result = $this->sync((string) $supplier->code);
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

    public function setupMtac(): Supplier
    {
        return $this->supplierProvisioner->ensureMtacSupplier();
    }
}
