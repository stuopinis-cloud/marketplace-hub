<?php

namespace App\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use RuntimeException;

/**
 * Reconciles supplier_products incorrectly keyed by merchant g:id after switching to g:SKU.
 */
class SupplierSkuReconciliationService
{
    public function __construct(
        private readonly SupplierXmlFeedClient $feedClient,
        private readonly SupplierXmlParser $parser,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     supplier_id: int,
     *     supplier_code: string,
     *     feed_entries: int,
     *     rename: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     unmatched_existing: list<array<string, mixed>>,
     *     already_correct: int,
     *     applied: int
     * }
     */
    public function reconcile(Supplier $supplier, bool $dryRun = true): array
    {
        if ($supplier->connector_type !== Supplier::CONNECTOR_XML_URL) {
            throw new RuntimeException('SKU reconciliation is only supported for XML URL suppliers.');
        }

        if (! SupplierXmlConfig::isConfigured($supplier)) {
            throw new RuntimeException('XML item path and SKU path mapping are required.');
        }

        $xml = $this->feedClient->fetch((string) $supplier->endpoint_url);
        $parsed = $this->parser->parse($xml, $supplier);
        $entries = $parsed['entries'];

        $byNewSku = [];
        $byEan = [];
        $byMerchantId = [];

        foreach ($entries as $entry) {
            $sku = trim((string) ($entry['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $byNewSku[$sku] = $entry;

            $ean = preg_replace('/\D+/', '', (string) ($entry['barcode'] ?? '')) ?? '';
            if ($ean !== '') {
                $byEan[$ean] = $byEan[$ean] ?? [];
                $byEan[$ean][] = $entry;
            }

            $merchantId = trim((string) ($entry['raw_payload']['merchant_id'] ?? ''));
            if ($merchantId !== '') {
                $byMerchantId[$merchantId] = $entry;
            }
        }

        $existing = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->orderBy('id')
            ->get();

        $rename = [];
        $conflicts = [];
        $unmatched = [];
        $alreadyCorrect = 0;
        $occupiedTargets = $existing->pluck('id', 'supplier_sku')->all();

        foreach ($existing as $product) {
            $currentSku = (string) $product->supplier_sku;

            if (isset($byNewSku[$currentSku])) {
                $alreadyCorrect++;

                continue;
            }

            $target = $this->resolveTargetEntry($product, $byMerchantId, $byEan);

            if ($target === null) {
                $unmatched[] = [
                    'supplier_product_id' => $product->id,
                    'current_sku' => $currentSku,
                    'ean' => $product->barcode,
                    'product_variant_id' => $product->product_variant_id,
                    'reason' => 'no_feed_match',
                ];

                continue;
            }

            $newSku = (string) $target['sku'];
            if ($newSku === $currentSku) {
                $alreadyCorrect++;

                continue;
            }

            $occupyingId = $occupiedTargets[$newSku] ?? null;
            if ($occupyingId !== null && (int) $occupyingId !== (int) $product->id) {
                $conflicts[] = [
                    'supplier_product_id' => $product->id,
                    'current_sku' => $currentSku,
                    'proposed_sku' => $newSku,
                    'ean' => $product->barcode,
                    'product_variant_id' => $product->product_variant_id,
                    'blocking_supplier_product_id' => $occupyingId,
                    'reason' => 'target_sku_already_exists',
                ];

                continue;
            }

            $feedEan = preg_replace('/\D+/', '', (string) ($target['barcode'] ?? '')) ?? '';
            $productEan = preg_replace('/\D+/', '', (string) ($product->barcode ?? '')) ?? '';
            if ($feedEan !== '' && $productEan !== '' && $feedEan !== $productEan) {
                $conflicts[] = [
                    'supplier_product_id' => $product->id,
                    'current_sku' => $currentSku,
                    'proposed_sku' => $newSku,
                    'ean' => $product->barcode,
                    'feed_ean' => $target['barcode'] ?? null,
                    'product_variant_id' => $product->product_variant_id,
                    'reason' => 'sku_ean_conflict',
                ];

                continue;
            }

            $rename[] = [
                'supplier_product_id' => $product->id,
                'current_sku' => $currentSku,
                'proposed_sku' => $newSku,
                'ean' => $product->barcode,
                'merchant_id' => $target['raw_payload']['merchant_id'] ?? null,
                'product_variant_id' => $product->product_variant_id,
                'match_status' => $product->match_status,
            ];
        }

        $applied = 0;
        if (! $dryRun) {
            foreach ($rename as $row) {
                $product = $existing->firstWhere('id', $row['supplier_product_id']);
                if (! $product instanceof SupplierProduct) {
                    continue;
                }

                $payload = is_array($product->raw_payload) ? $product->raw_payload : [];
                $payload['reconciled_from_sku'] = $product->supplier_sku;
                $payload['merchant_id'] = $row['merchant_id'] ?? ($payload['merchant_id'] ?? null);
                $payload['sku'] = $row['proposed_sku'];
                $payload['sku_field'] = SupplierXmlConfig::skuPath($supplier);

                $product->update([
                    'supplier_sku' => $row['proposed_sku'],
                    'raw_payload' => $payload,
                ]);
                $applied++;
            }
        }

        return [
            'dry_run' => $dryRun,
            'supplier_id' => $supplier->id,
            'supplier_code' => (string) $supplier->code,
            'feed_entries' => count($entries),
            'rename' => $rename,
            'conflicts' => $conflicts,
            'unmatched_existing' => $unmatched,
            'already_correct' => $alreadyCorrect,
            'applied' => $applied,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byMerchantId
     * @param  array<string, list<array<string, mixed>>>  $byEan
     * @return array<string, mixed>|null
     */
    private function resolveTargetEntry(SupplierProduct $product, array $byMerchantId, array $byEan): ?array
    {
        $currentSku = (string) $product->supplier_sku;
        if (isset($byMerchantId[$currentSku])) {
            return $byMerchantId[$currentSku];
        }

        $payloadMerchantId = trim((string) data_get($product->raw_payload, 'merchant_id', ''));
        if ($payloadMerchantId !== '' && isset($byMerchantId[$payloadMerchantId])) {
            return $byMerchantId[$payloadMerchantId];
        }

        $ean = preg_replace('/\D+/', '', (string) ($product->barcode ?? '')) ?? '';
        if ($ean !== '' && isset($byEan[$ean]) && count($byEan[$ean]) === 1) {
            return $byEan[$ean][0];
        }

        return null;
    }
}
