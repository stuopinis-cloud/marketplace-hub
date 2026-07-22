<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use App\Services\Suppliers\Csv\SupplierCsvSupplierImporter;
use App\Services\Suppliers\Helik\HelikSupplierImporter;
use App\Services\Suppliers\Json\SupplierJsonConfig;
use App\Services\Suppliers\Json\SupplierJsonSupplierImporter;
use App\Services\Suppliers\Mtac\MtacSupplierImporter;
use App\Services\Suppliers\Xml\SupplierXmlConfig;
use App\Services\Suppliers\Xml\SupplierXmlSupplierImporter;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class SupplierSyncManager
{
    public function __construct(
        private readonly MtacSupplierImporter $mtacImporter,
        private readonly HelikSupplierImporter $helikImporter,
        private readonly SupplierCsvSupplierImporter $csvImporter,
        private readonly SupplierXmlSupplierImporter $xmlImporter,
        private readonly SupplierJsonSupplierImporter $jsonImporter,
        private readonly SupplierProvisioner $supplierProvisioner,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedConnectorTypes(): array
    {
        return [
            Supplier::CONNECTOR_CSV_URL,
            Supplier::CONNECTOR_CSV_UPLOAD,
            Supplier::CONNECTOR_XML_URL,
            Supplier::CONNECTOR_JSON_API,
            Supplier::CONNECTOR_API,
            Supplier::CONNECTOR_MTAC,
            Supplier::CONNECTOR_HELIK_API,
        ];
    }

    public function supportsConnector(?string $connectorType): bool
    {
        return in_array($connectorType, $this->supportedConnectorTypes(), true);
    }

    public function sync(string $supplierCode, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $supplierCode = mb_strtolower($supplierCode);
        $supplier = Supplier::query()->where('code', $supplierCode)->first();

        // Built-in adapters may still be provisioned lazily when syncing by known code.
        if ($supplier === null && $supplierCode === Supplier::CODE_MTAC) {
            $supplier = $this->supplierProvisioner->ensureMtacSupplier();
        }

        if ($supplier === null && $supplierCode === Supplier::CODE_HELIK) {
            $supplier = $this->supplierProvisioner->ensureHelikSupplier();
        }

        if (! $supplier instanceof Supplier) {
            throw new InvalidArgumentException('Unknown supplier code: '.$supplierCode);
        }

        if (! $this->supportsConnector($supplier->connector_type)) {
            throw new InvalidArgumentException('Unsupported supplier connector type: '.$supplier->connector_type);
        }

        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_MTAC => $this->mtacImporter->sync($options),
            Supplier::CONNECTOR_HELIK_API => $this->helikImporter->sync($options),
            Supplier::CONNECTOR_XML_URL => $this->syncXmlUrlSupplier($supplier, $options),
            Supplier::CONNECTOR_API => $this->syncApiSupplier($supplier, $options),
            Supplier::CONNECTOR_JSON_API => $this->jsonImporter->sync($supplier, $options),
            Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->csvImporter->sync($supplier, $options),
            default => throw new InvalidArgumentException('Unsupported supplier connector type: '.$supplier->connector_type),
        };
    }

    private function syncXmlUrlSupplier(Supplier $supplier, ?SupplierSyncOptions $options): SupplierSyncResult
    {
        if ($supplier->code === Supplier::CODE_MTAC && ! SupplierXmlConfig::isConfigured($supplier)) {
            return $this->mtacImporter->sync($options);
        }

        return $this->xmlImporter->sync($supplier, $options);
    }

    private function syncApiSupplier(Supplier $supplier, ?SupplierSyncOptions $options): SupplierSyncResult
    {
        if ($supplier->code === Supplier::CODE_HELIK || $supplier->connector_type === Supplier::CONNECTOR_HELIK_API) {
            return $this->helikImporter->sync($options);
        }

        if (SupplierJsonConfig::isConfigured($supplier)) {
            return $this->jsonImporter->sync($supplier, $options);
        }

        throw new InvalidArgumentException('Unsupported API supplier: '.$supplier->code);
    }

    public function syncMtac(?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $this->supplierProvisioner->ensureMtacSupplier();

        return $this->mtacImporter->sync($options);
    }

    public function syncHelik(?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $this->supplierProvisioner->ensureHelikSupplier();

        return $this->helikImporter->sync($options);
    }

    public function isDueForSync(Supplier $supplier, bool $force = false): bool
    {
        if ($force || $supplier->force_daily_sync) {
            return true;
        }

        if ($supplier->last_sync_at === null) {
            return true;
        }

        $interval = $supplier->sync_interval_minutes;

        if ($interval === null || $interval <= 0) {
            return true;
        }

        return $supplier->last_sync_at->lte(now()->subMinutes($interval));
    }

    public function shouldBlockDailySyncOnFailure(Supplier $supplier): bool
    {
        return (bool) data_get($supplier->config, 'block_daily_sync_on_failure', false);
    }

    public function isReadyToSync(Supplier $supplier): bool
    {
        if (! $this->supportsConnector($supplier->connector_type)) {
            return false;
        }

        if ($supplier->connector_type === Supplier::CONNECTOR_CSV_UPLOAD) {
            $path = SupplierCsvConfig::uploadedFilePath($supplier);

            if (blank($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>|null  $onlyCodes
     * @return Collection<int, Supplier>
     */
    public function enabledSuppliers(?array $onlyCodes = null): Collection
    {
        $query = Supplier::query()
            ->where('enabled', true)
            ->where('sync_enabled', true)
            ->whereIn('connector_type', $this->supportedConnectorTypes())
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->orderBy('stock_priority')
            ->orderBy('id');

        if ($onlyCodes !== null) {
            $normalized = array_values(array_filter(array_map(
                fn (string $code): string => mb_strtolower(trim($code)),
                $onlyCodes,
            )));

            $query->whereIn('code', $normalized);
        }

        return $query->get()->filter(fn (Supplier $supplier): bool => $this->isReadyToSync($supplier))->values();
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     result?: SupplierSyncResult,
     *     error?: string,
     *     skipped?: string,
     *     blocked?: bool
     * }>
     */
    public function syncPublicationSuppliers(?SupplierSyncOptions $options = null): array
    {
        $options ??= new SupplierSyncOptions;

        return $this->syncSuppliers(
            $this->enabledSuppliers($options->only),
            $options,
            respectInterval: ! $options->force,
        );
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     result?: SupplierSyncResult,
     *     error?: string,
     *     skipped?: string,
     *     blocked?: bool
     * }>
     */
    public function syncEnabledSuppliers(?SupplierSyncOptions $options = null): array
    {
        $options ??= new SupplierSyncOptions;

        return $this->syncSuppliers(
            $this->enabledSuppliers($options->only),
            $options,
            respectInterval: ! $options->force,
        );
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     result?: SupplierSyncResult,
     *     error?: string,
     *     skipped?: string,
     *     blocked?: bool
     * }>
     */
    public function syncAll(?SupplierSyncOptions $options = null): array
    {
        return $this->syncEnabledSuppliers($options);
    }

    /**
     * @return array<int, array{code: string, name: string, result?: SupplierSyncResult, error?: string}>
     */
    public function syncEnabledCsvSuppliers(?SupplierSyncOptions $options = null): array
    {
        $options ??= new SupplierSyncOptions;

        $suppliers = $this->enabledSuppliers($options->only)
            ->filter(fn (Supplier $supplier): bool => in_array($supplier->connector_type, [
                Supplier::CONNECTOR_CSV_URL,
                Supplier::CONNECTOR_CSV_UPLOAD,
            ], true))
            ->values();

        return $this->syncSuppliers($suppliers, $options, respectInterval: ! $options->force);
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     result?: SupplierSyncResult,
     *     error?: string,
     *     skipped?: string,
     *     blocked?: bool
     * }>
     */
    private function syncSuppliers(Collection $suppliers, SupplierSyncOptions $options, bool $respectInterval): array
    {
        $results = [];

        foreach ($suppliers as $supplier) {
            $code = (string) $supplier->code;
            $name = (string) $supplier->name;

            if ($respectInterval && ! $this->isDueForSync($supplier, $options->force)) {
                $results[] = [
                    'code' => $code,
                    'name' => $name,
                    'skipped' => 'not_due',
                ];

                continue;
            }

            $results[] = $this->syncSupplierWithLock($supplier, $options);
        }

        return $results;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     result?: SupplierSyncResult,
     *     error?: string,
     *     skipped?: string,
     *     blocked?: bool
     * }
     */
    private function syncSupplierWithLock(Supplier $supplier, SupplierSyncOptions $options): array
    {
        $code = (string) $supplier->code;
        $name = (string) $supplier->name;
        $lockKey = MarketplaceJobLock::forSupplier($code);
        $lock = MarketplaceJobLock::make($lockKey);

        if (! $lock->get()) {
            return [
                'code' => $code,
                'name' => $name,
                'skipped' => 'already_running',
                'error' => 'Supplier sync already running.',
                'blocked' => $this->shouldBlockDailySyncOnFailure($supplier),
            ];
        }

        try {
            $result = $this->sync($code, $options);

            return [
                'code' => $code,
                'name' => $name,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            $this->recordSupplierFailure($supplier, $exception);

            return [
                'code' => $code,
                'name' => $name,
                'error' => $exception->getMessage(),
                'blocked' => $this->shouldBlockDailySyncOnFailure($supplier),
            ];
        } finally {
            $lock->release();
        }
    }

    private function recordSupplierFailure(Supplier $supplier, Throwable $exception): void
    {
        $supplier->forceFill([
            'last_sync_status' => 'failed',
            'last_sync_message' => $exception->getMessage(),
        ])->save();
    }

    public function setupMtac(): Supplier
    {
        return $this->supplierProvisioner->ensureMtacSupplier();
    }

    public function setupHelik(): Supplier
    {
        return $this->supplierProvisioner->ensureHelikSupplier();
    }

    public function setupPrezioso(): Supplier
    {
        return $this->supplierProvisioner->ensurePreziosoSupplier();
    }
}
