<?php

namespace App\Services\Suppliers\Mtac;

use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Suppliers\SupplierProvisioner;
use RuntimeException;
use Throwable;

class MtacSupplierImporter
{
    public function __construct(
        private readonly SupplierProvisioner $supplierProvisioner,
        private readonly MtacFeedClient $feedClient,
        private readonly MtacXmlParser $xmlParser,
        private readonly MtacSkuMatcher $skuMatcher,
    ) {}

    public function sync(?MtacSupplierSyncOptions $options = null): MtacSupplierSyncResult
    {
        $options ??= new MtacSupplierSyncOptions;
        $supplier = $this->supplierProvisioner->ensureMtacSupplier();

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('M-Tac supplier endpoint URL is not configured.');
        }

        $syncJob = $this->startSyncJob($supplier);
        $stats = [
            'parsed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'duplicate_supplier_sku' => 0,
            'positive_stock' => 0,
            'zero_stock' => 0,
            'failed_rows' => 0,
            'missing_from_feed' => 0,
        ];

        try {
            $xml = $this->feedClient->fetch((string) $supplier->endpoint_url);
            $entries = $this->xmlParser->parse($xml);
            $entries = $this->filterEntries($entries, $options);
            $duplicateSupplierSkus = $this->skuMatcher->duplicateSupplierSkus($entries);

            $shopifyVariants = $this->loadShopifyVariants();
            $existingMappings = SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->with('productVariant')
                ->get();

            $seenSupplierSkus = [];
            $seenSupplierProductIds = [];

            foreach ($entries as $entry) {
                $stats['parsed']++;
                $normalizedSku = $this->skuMatcher->normalizeSku($entry['sku']);

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

                $match = $this->skuMatcher->match($entry['sku'], $shopifyVariants, $existingMappings);

                if ($match['issue_code'] === 'duplicate_shopify_sku') {
                    $stats['ambiguous']++;
                    $this->recordSyncJobItem(
                        $syncJob,
                        $entry['sku'],
                        SyncJobItemStatus::Failed,
                        'duplicate_shopify_sku',
                        'Multiple Shopify variants share the same SKU for M-Tac vendor.',
                        $entry,
                    );
                    $stats['failed_rows']++;
                }

                if ($match['match_status'] === SupplierProduct::MATCH_STATUS_MATCHED) {
                    $stats['matched']++;
                } elseif ($match['match_status'] === SupplierProduct::MATCH_STATUS_UNMATCHED) {
                    $stats['unmatched']++;
                }

                if ($entry['stock_quantity'] > 0) {
                    $stats['positive_stock']++;
                } else {
                    $stats['zero_stock']++;
                }

                if (! $options->dryRun) {
                    $supplierProduct = $this->upsertSupplierProduct(
                        $supplier,
                        $entry,
                        $match,
                    );
                    $seenSupplierProductIds[] = $supplierProduct->id;
                }

                $seenSupplierSkus[$normalizedSku] = true;
                $this->touchSyncJob($syncJob, $stats);
            }

            if (! $options->dryRun && ! $options->isPartialRun()) {
                $stats['missing_from_feed'] = $this->markMissingFromFeed($supplier, $seenSupplierProductIds);
            }

            $this->finishSyncJob($syncJob, $supplier, $stats, $options->dryRun);

            return new MtacSupplierSyncResult(
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
            );
        } catch (Throwable $exception) {
            $this->failSyncJob($syncJob, $supplier, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{sku: string, stock_quantity: int, availability_status: string, raw_payload: array<string, mixed>}>  $entries
     * @return array<int, array{sku: string, stock_quantity: int, availability_status: string, raw_payload: array<string, mixed>}>
     */
    private function filterEntries(array $entries, MtacSupplierSyncOptions $options): array
    {
        if (filled($options->sku)) {
            $target = $this->skuMatcher->normalizeSku($options->sku);

            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $this->skuMatcher->normalizeSku($entry['sku']) === $target,
            ));
        }

        if ($options->limit !== null) {
            $entries = array_slice($entries, 0, max(0, $options->limit));
        }

        return $entries;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    private function loadShopifyVariants()
    {
        return ProductVariant::query()
            ->whereHas('product', function ($query): void {
                $query->whereRaw('LOWER(TRIM(vendor)) = ?', [mb_strtolower(MtacSkuMatcher::VENDOR)]);
            })
            ->with('product')
            ->get();
    }

    /**
     * @param  array{
     *     sku: string,
     *     stock_quantity: int,
     *     availability_status: string,
     *     raw_payload: array<string, mixed>
     * }  $entry
     * @param  array{
     *     variant: ?ProductVariant,
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

    private function startSyncJob(Supplier $supplier): SyncJob
    {
        return SyncJob::query()->create([
            'type' => 'import',
            'source' => 'supplier:mtac',
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
}
