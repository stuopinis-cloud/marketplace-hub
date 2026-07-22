<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Enums\SyncJobStatus;

class SupplierHealthService
{
    /**
     * @return array{
     *     total: int,
     *     enabled: int,
     *     due_now: int,
     *     failed_last_sync: int,
     *     unmatched_total: int,
     *     ambiguous_total: int
     * }
     */
    public function snapshot(): array
    {
        $manager = app(SupplierSyncManager::class);

        $suppliers = Supplier::query()->orderBy('name')->get();
        $enabled = $suppliers->filter(
            fn (Supplier $supplier): bool => $supplier->enabled
                && $supplier->sync_enabled
                && $manager->supportsConnector($supplier->connector_type)
                && $manager->isReadyToSync($supplier),
        );

        $dueNow = $enabled->filter(fn (Supplier $supplier): bool => $manager->isDueForSync($supplier))->count();

        return [
            'total' => $suppliers->count(),
            'enabled' => $enabled->count(),
            'due_now' => $dueNow,
            'failed_last_sync' => $suppliers->filter(
                fn (Supplier $supplier): bool => $supplier->last_sync_status === SyncJobStatus::Failed->value
                    || $supplier->last_sync_status === 'failed',
            )->count(),
            'unmatched_total' => SupplierProduct::query()
                ->where('match_status', SupplierProduct::MATCH_STATUS_UNMATCHED)
                ->count(),
            'ambiguous_total' => SupplierProduct::query()
                ->where('match_status', SupplierProduct::MATCH_STATUS_AMBIGUOUS)
                ->count(),
        ];
    }
}
