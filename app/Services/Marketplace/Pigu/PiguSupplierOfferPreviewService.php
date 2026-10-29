<?php

namespace App\Services\Marketplace\Pigu;

use App\Jobs\GeneratePiguSupplierOfferPreviewJob;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Isolated dry-run of Pigu offers (contribution or full combined feed).
 * Generation runs via queue; HTTP only reads status / downloads ready artifacts.
 */
class PiguSupplierOfferPreviewService
{
    public function __construct(
        private readonly PiguOfferFeedExporter $exporter,
        private readonly PiguSupplierOfferPreviewStatus $statusStore,
        private readonly PiguOfferFeedComparer $comparer = new PiguOfferFeedComparer,
    ) {}

    /**
     * @return array{dispatched: bool, status: array<string, mixed>, message: string}
     */
    public function dispatch(
        Supplier $supplier,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $scope = $this->normalizeScope($scope);

        if ($this->statusStore->isBusy($supplier->id, $scope)) {
            return [
                'dispatched' => false,
                'status' => $this->statusStore->read($supplier->id, $scope),
                'message' => 'Peržiūros generavimas šiam tiekėjui jau vyksta.',
            ];
        }

        $status = $this->statusStore->update($supplier->id, [
            'status' => PiguSupplierOfferPreviewStatus::STATUS_PENDING,
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'duration_seconds' => null,
            'products_processed' => 0,
            'offers_generated' => 0,
            'rejected' => 0,
            'error' => null,
            'stats' => null,
        ], $scope);

        GeneratePiguSupplierOfferPreviewJob::dispatch($supplier->id, $scope);

        return [
            'dispatched' => true,
            'status' => $status,
            'message' => $scope === PiguExportOptions::PREVIEW_SCOPE_COMBINED
                ? 'Bendros Pigu XML peržiūros generavimas įtrauktas į eilę.'
                : 'Peržiūros generavimas įtrauktas į eilę.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(
        Supplier $supplier,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $scope = $this->normalizeScope($scope);
        $status = $this->statusStore->read($supplier->id, $scope);
        $status['pigu_enabled_saved'] = PiguSupplierOfferConfig::isEnabled($supplier);
        $status['supplier_name'] = $supplier->name;
        $status['total_supplier_products'] = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->where('enabled', true)
            ->count();
        $status['files_ready'] = $this->filesReady($supplier->id, $scope);
        $status['can_download'] = ($status['status'] ?? null) === PiguSupplierOfferPreviewStatus::STATUS_READY
            && $status['files_ready'];

        return $status;
    }

    /**
     * Execute generation (queue worker only). Streams XML/CSV; does not mutate supplier config.
     *
     * @return array<string, mixed>
     */
    public function runQueuedGeneration(
        Supplier $supplier,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $scope = $this->normalizeScope($scope);
        $enabledBefore = PiguSupplierOfferConfig::isEnabled($supplier);
        $startedAt = microtime(true);

        $this->statusStore->update($supplier->id, [
            'status' => PiguSupplierOfferPreviewStatus::STATUS_RUNNING,
            'started_at' => now()->toIso8601String(),
            'error' => null,
        ], $scope);

        try {
            $options = $scope === PiguExportOptions::PREVIEW_SCOPE_COMBINED
                ? PiguExportOptions::combinedPreview($supplier->id, true)
                : PiguExportOptions::supplierPreview($supplier->id, true);

            $result = $this->exporter->export(
                $options,
                function (array $progress) use ($supplier, $scope): void {
                    $this->statusStore->update($supplier->id, [
                        'status' => PiguSupplierOfferPreviewStatus::STATUS_RUNNING,
                        'products_processed' => (int) ($progress['products_processed'] ?? 0),
                        'offers_generated' => (int) ($progress['offers_generated'] ?? 0),
                        'rejected' => (int) ($progress['rejected'] ?? 0),
                    ], $scope);
                },
            );

            $supplier->refresh();
            if (PiguSupplierOfferConfig::isEnabled($supplier) !== $enabledBefore) {
                throw new RuntimeException('Preview must not mutate supplier Pigu activation state.');
            }

            if (($result['disk'] ?? null) !== PiguOfferFeedExporter::PREVIEW_DISK) {
                throw new RuntimeException('Preview wrote to an unexpected storage disk.');
            }

            if (str_contains((string) $result['feed_path'], PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE)) {
                throw new RuntimeException('Preview must not write the production pigu-offers.xml path.');
            }

            $previewStats = $result['preview_stats'] ?? [];
            $duration = round(microtime(true) - $startedAt, 2);

            $comparison = null;
            if ($scope === PiguExportOptions::PREVIEW_SCOPE_COMBINED) {
                $baselineAbsolute = Storage::disk('public')->exists(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE)
                    ? Storage::disk('public')->path(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE)
                    : null;

                $comparison = $this->comparer->compareFiles(
                    $baselineAbsolute,
                    (string) $result['absolute_path'],
                    is_array($result['skipped_by_code'] ?? null) ? $result['skipped_by_code'] : [],
                );
            }

            $stats = [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'pigu_enabled_saved' => $enabledBefore,
                'preview_scope' => $scope,
                'feed_path' => (string) $result['feed_path'],
                'absolute_path' => (string) $result['absolute_path'],
                'skipped_csv_path' => (string) $result['failed_csv_path'],
                'skipped_csv_absolute_path' => Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK)
                    ->path((string) $result['failed_csv_path']),
                'disk' => PiguOfferFeedExporter::PREVIEW_DISK,
                'total_supplier_products' => SupplierProduct::query()
                    ->where('supplier_id', $supplier->id)
                    ->where('enabled', true)
                    ->count(),
                'valid_offers' => (int) ($result['offers_exported'] ?? 0),
                'local_offers_count' => (int) ($result['local_offers_count'] ?? 0),
                'supplier_offers_count' => (int) ($result['supplier_offers_count'] ?? 0),
                'shopify_matched_offers' => (int) ($previewStats['shopify_matched_offers'] ?? 0),
                'supplier_only_offers' => (int) ($result['supplier_only_offers_count']
                    ?? $previewStats['supplier_only_offers']
                    ?? 0),
                'backorder_offers_count' => (int) ($result['backorder_offers_count'] ?? 0),
                'deduplicated_offers' => (int) ($previewStats['deduplicated_offers'] ?? 0),
                'skipped_by_reason' => $previewStats['skipped_by_reason'] ?? ($result['skipped_by_code'] ?? []),
                'examples' => $result['examples'] ?? [],
                'diagnostics' => $result['diagnostics'] ?? [],
                'comparison' => $comparison,
                'export' => [
                    'offers_exported' => $result['offers_exported'],
                    'offers_skipped' => $result['offers_skipped'],
                    'variants_considered' => $result['variants_considered'],
                    'supplier_only_considered' => $result['supplier_only_considered'] ?? 0,
                    'offers_by_collectionhours' => $result['offers_by_collectionhours'] ?? [],
                ],
            ];

            $this->statusStore->update($supplier->id, [
                'status' => PiguSupplierOfferPreviewStatus::STATUS_READY,
                'finished_at' => now()->toIso8601String(),
                'duration_seconds' => $duration,
                'products_processed' => (int) (($result['variants_considered'] ?? 0) + ($result['supplier_only_considered'] ?? 0)),
                'offers_generated' => (int) ($result['offers_exported'] ?? 0),
                'rejected' => (int) ($result['offers_skipped'] ?? 0),
                'error' => null,
                'stats' => $stats,
            ], $scope);

            return $stats;
        } catch (Throwable $e) {
            $this->statusStore->update($supplier->id, [
                'status' => PiguSupplierOfferPreviewStatus::STATUS_FAILED,
                'finished_at' => now()->toIso8601String(),
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'error' => $e->getMessage(),
            ], $scope);

            throw $e;
        }
    }

    /**
     * Synchronous helper for tests / CLI. Prefer {@see dispatch()} in HTTP.
     *
     * @return array<string, mixed>
     */
    public function generate(
        Supplier $supplier,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        return $this->runQueuedGeneration($supplier, $scope);
    }

    /**
     * @return array{feed_path: string, absolute_path: string, skipped_csv_path: string, skipped_csv_absolute_path: string, disk: string, supplier_id: int}
     */
    public function readyDownloadPaths(
        Supplier $supplier,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $scope = $this->normalizeScope($scope);
        $status = $this->statusStore->read($supplier->id, $scope);

        if (($status['status'] ?? null) !== PiguSupplierOfferPreviewStatus::STATUS_READY || ! $this->filesReady($supplier->id, $scope)) {
            throw new RuntimeException('Pigu XML peržiūra dar neparuošta. Pirmiausia sugeneruokite peržiūrą.');
        }

        $dir = PiguOfferFeedExporter::previewDirectory($supplier->id, $scope);
        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $xmlPath = $dir.'/offers.xml';
        $csvPath = $dir.'/skipped.csv';

        return [
            'feed_path' => $xmlPath,
            'absolute_path' => $disk->path($xmlPath),
            'skipped_csv_path' => $csvPath,
            'skipped_csv_absolute_path' => $disk->path($csvPath),
            'disk' => PiguOfferFeedExporter::PREVIEW_DISK,
            'supplier_id' => $supplier->id,
        ];
    }

    private function filesReady(int $supplierId, string $scope): bool
    {
        $dir = PiguOfferFeedExporter::previewDirectory($supplierId, $scope);
        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);

        return $disk->exists($dir.'/offers.xml') && $disk->exists($dir.'/skipped.csv');
    }

    private function normalizeScope(string $scope): string
    {
        return $scope === PiguExportOptions::PREVIEW_SCOPE_COMBINED
            ? PiguExportOptions::PREVIEW_SCOPE_COMBINED
            : PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION;
    }
}
