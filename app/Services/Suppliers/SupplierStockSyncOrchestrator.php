<?php

namespace App\Services\Suppliers;

use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Throwable;

class SupplierStockSyncOrchestrator
{
    /**
     * @param  array<int, string>  $vendorScope
     * @param  array<int, array{
     *     sku: string,
     *     stock_quantity: ?int,
     *     availability_status: string,
     *     raw_payload: array<string, mixed>
     * }>  $entries
     */
    public function sync(
        Supplier $supplier,
        string $syncJobSource,
        array $vendorScope,
        array $entries,
        SupplierSyncOptions $options,
        string $ambiguousMatchMessage,
        array $preSkippedRows = [],
    ): SupplierSyncResult {
        $skuMatcher = new SupplierSkuMatcher($vendorScope);
        $syncJob = $this->startSyncJob($supplier, $syncJobSource);
        $stats = $this->initialStats();

        try {
            $this->recordSkippedRows($syncJob, $stats, $preSkippedRows);

            $entries = $this->filterEntries($entries, $skuMatcher, $options);
            $duplicateSupplierSkus = $skuMatcher->duplicateSupplierSkus($entries);
            $shopifyVariants = $skuMatcher->loadShopifyVariants();
            $existingMappings = SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->with('productVariant')
                ->get();

            $seenSupplierProductIds = [];

            foreach ($entries as $entry) {
                $stats['parsed']++;
                $normalizedSku = $skuMatcher->normalizeSku($entry['sku']);

                if (isset($duplicateSupplierSkus[$normalizedSku])) {
                    $stats['duplicate_supplier_sku']++;
                    $this->recordSyncJobItem(
                        $syncJob,
                        $entry['sku'],
                        SyncJobItemStatus::Failed,
                        'duplicate_supplier_sku',
                        'Duplicate supplier SKU in feed.',
                        $entry,
                    );
                    $stats['failed_rows']++;

                    continue;
                }

                $match = $skuMatcher->match($entry['sku'], $shopifyVariants, $existingMappings);

                if ($match['issue_code'] === 'duplicate_shopify_sku') {
                    $stats['ambiguous']++;
                    $stats['duplicate_shopify_sku']++;
                    $this->recordSyncJobItem(
                        $syncJob,
                        $entry['sku'],
                        SyncJobItemStatus::Failed,
                        'duplicate_shopify_sku',
                        $ambiguousMatchMessage,
                        $entry,
                    );
                    $stats['failed_rows']++;
                }

                if ($match['match_status'] === SupplierProduct::MATCH_STATUS_MATCHED) {
                    $stats['matched']++;
                } elseif ($match['match_status'] === SupplierProduct::MATCH_STATUS_UNMATCHED) {
                    $stats['unmatched']++;
                }

                if (($entry['parse_issue_code'] ?? null) === 'missing_quantity') {
                    $stats['missing_quantity']++;
                }

                if (($entry['stock_quantity'] ?? 0) > 0) {
                    $stats['positive_stock']++;
                } else {
                    $stats['zero_stock']++;
                }

                if (! $options->dryRun) {
                    $supplierProduct = $this->upsertSupplierProduct($supplier, $entry, $match);
                    $seenSupplierProductIds[] = $supplierProduct->id;
                }

                $this->touchSyncJob($syncJob, $stats);
            }

            if (! $options->dryRun && ! $options->isPartialRun()) {
                $stats['missing_from_feed'] = $this->markMissingFromFeed($supplier, $seenSupplierProductIds);
            }

            $this->finishSyncJob($syncJob, $supplier, $stats, $options->dryRun);

            return $this->buildResult($syncJob, $stats);
        } catch (Throwable $exception) {
            $this->failSyncJob($syncJob, $supplier, $exception);

            throw $exception;
        }
    }

    public function recordSkippedRows(SyncJob $syncJob, array &$stats, array $skippedRows): void
    {
        foreach ($skippedRows as $skipped) {
            $issueCode = (string) ($skipped['issue_code'] ?? 'failed_row');

            if ($issueCode === 'missing_sku') {
                $stats['missing_sku']++;
            }

            $stats['failed_rows']++;
            $this->recordSyncJobItem(
                $syncJob,
                (string) ($skipped['sku'] ?? '—'),
                SyncJobItemStatus::Failed,
                $issueCode,
                (string) ($skipped['message'] ?? 'Skipped row.'),
                $skipped,
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function initialStats(): array
    {
        return [
            'parsed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'duplicate_supplier_sku' => 0,
            'duplicate_shopify_sku' => 0,
            'positive_stock' => 0,
            'zero_stock' => 0,
            'failed_rows' => 0,
            'missing_from_feed' => 0,
            'missing_sku' => 0,
            'missing_quantity' => 0,
        ];
    }

    /**
     * @param  array<int, array{sku: string, stock_quantity: int, availability_status: string, raw_payload: array<string, mixed>}>  $entries
     * @return array<int, array{sku: string, stock_quantity: int, availability_status: string, raw_payload: array<string, mixed>}>
     */
    private function filterEntries(array $entries, SupplierSkuMatcher $skuMatcher, SupplierSyncOptions $options): array
    {
        if (filled($options->sku)) {
            $target = $skuMatcher->normalizeSku($options->sku);

            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $skuMatcher->normalizeSku($entry['sku']) === $target,
            ));
        }

        if ($options->limit !== null) {
            $entries = array_slice($entries, 0, max(0, $options->limit));
        }

        return $entries;
    }

    /**
     * @param  array{
     *     sku: string,
     *     stock_quantity: ?int,
     *     availability_status: string,
     *     raw_payload: array<string, mixed>
     * }  $entry
     * @param  array{
     *     variant: ?\App\Models\ProductVariant,
     *     match_status: string,
     *     match_method: ?string,
     *     issue_code: ?string
     * }  $match
     */
    private function upsertSupplierProduct(Supplier $supplier, array $entry, array $match): SupplierProduct
    {
        $now = now();

        return SupplierProduct::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->id,
                'supplier_sku' => $entry['sku'],
            ],
            [
                'product_variant_id' => $match['variant']?->id,
                'stock_quantity' => $entry['stock_quantity'],
                'availability_status' => $entry['availability_status'],
                'raw_payload' => $entry['raw_payload'],
                'match_status' => $match['match_status'],
                'match_method' => $match['match_method'],
                'enabled' => true,
                'last_synced_at' => $now,
                'last_seen_at' => $now,
                'stale_at' => null,
            ],
        );
    }

    /**
     * @param  array<int, int>  $seenSupplierProductIds
     */
    private function markMissingFromFeed(Supplier $supplier, array $seenSupplierProductIds): int
    {
        $query = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('last_seen_at');

        if ($seenSupplierProductIds !== []) {
            $query->whereNotIn('id', $seenSupplierProductIds);
        }

        $count = 0;

        $query->each(function (SupplierProduct $supplierProduct) use (&$count): void {
            $supplierProduct->update([
                'stock_quantity' => 0,
                'availability_status' => SupplierProduct::AVAILABILITY_MISSING_FROM_FEED,
                'last_synced_at' => now(),
            ]);
            $count++;
        });

        return $count;
    }

    private function startSyncJob(Supplier $supplier, string $source): SyncJob
    {
        return SyncJob::query()->create([
            'type' => 'import',
            'source' => $source,
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid(),
            'context' => [
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function touchSyncJob(SyncJob $syncJob, array $stats): void
    {
        $syncJob->update([
            'total_items' => $stats['parsed'],
            'success_items' => $stats['matched'],
            'failed_items' => $stats['failed_rows'],
            'heartbeat_at' => now(),
            'context' => array_merge($syncJob->context ?? [], ['stats' => $stats]),
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function finishSyncJob(SyncJob $syncJob, Supplier $supplier, array $stats, bool $dryRun): void
    {
        $status = match (true) {
            $stats['failed_rows'] > 0 && $stats['matched'] === 0 => SyncJobStatus::Failed,
            $stats['failed_rows'] > 0 => SyncJobStatus::Partial,
            default => SyncJobStatus::Completed,
        };

        $syncJob->update([
            'status' => $status,
            'total_items' => $stats['parsed'],
            'success_items' => $stats['matched'],
            'failed_items' => $stats['failed_rows'],
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'context' => array_merge($syncJob->context ?? [], [
                'stats' => $stats,
                'dry_run' => $dryRun,
            ]),
        ]);

        if (! $dryRun) {
            $supplier->update([
                'last_sync_at' => now(),
                'last_sync_status' => $status->value,
            ]);
        }
    }

    private function failSyncJob(SyncJob $syncJob, Supplier $supplier, Throwable $exception): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'error_message' => $exception->getMessage(),
        ]);

        $supplier->update([
            'last_sync_status' => SyncJobStatus::Failed->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSyncJobItem(
        SyncJob $syncJob,
        string $sku,
        SyncJobItemStatus $status,
        string $issueCode,
        string $message,
        array $payload,
    ): void {
        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'status' => $status,
            'sku' => $sku,
            'message' => $message,
            'payload' => array_merge($payload, ['issue_code' => $issueCode]),
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function buildResult(SyncJob $syncJob, array $stats): SupplierSyncResult
    {
        return new SupplierSyncResult(
            syncJobId: $syncJob->id,
            parsed: $stats['parsed'],
            matched: $stats['matched'],
            unmatched: $stats['unmatched'],
            ambiguous: $stats['ambiguous'],
            duplicateSupplierSku: $stats['duplicate_supplier_sku'],
            positiveStock: $stats['positive_stock'],
            zeroStock: $stats['zero_stock'],
            failedRows: $stats['failed_rows'],
            missingFromFeed: $stats['missing_from_feed'],
            duplicateShopifySku: $stats['duplicate_shopify_sku'],
            missingSku: $stats['missing_sku'],
            missingQuantity: $stats['missing_quantity'],
        );
    }
}
