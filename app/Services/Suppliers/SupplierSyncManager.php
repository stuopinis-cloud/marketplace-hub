<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Helik\HelikSupplierImporter;
use App\Services\Suppliers\Mtac\MtacSupplierImporter;
use InvalidArgumentException;

class SupplierSyncManager
{
    public function __construct(
        private readonly MtacSupplierImporter $mtacImporter,
        private readonly HelikSupplierImporter $helikImporter,
        private readonly SupplierProvisioner $supplierProvisioner,
    ) {}

    public function sync(string $supplierCode, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        return match (mb_strtolower($supplierCode)) {
            Supplier::CODE_MTAC => $this->mtacImporter->sync($options),
            Supplier::CODE_HELIK => $this->helikImporter->sync($options),
            default => throw new InvalidArgumentException('Unsupported supplier code: '.$supplierCode),
        };
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
