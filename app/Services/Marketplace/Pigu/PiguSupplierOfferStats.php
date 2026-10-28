<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\FeedFile;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use Illuminate\Support\Collection;

/**
 * Aggregates admin-facing Pigu offers / supplier XML diagnostics (Lithuanian UI).
 */
class PiguSupplierOfferStats
{
    /**
     * @return array{
     *     total_products: int,
     *     valid_offers: int,
     *     missing_ean: int,
     *     missing_sku: int,
     *     invalid_prices: int,
     *     out_of_stock: int,
     *     in_shopify: int,
     *     supplier_only: int,
     *     ambiguous: int,
     *     stale: int,
     *     estimated_additional_offers: int
     * }
     */
    public function reviewForSupplier(Supplier $supplier): array
    {
        $products = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->where('enabled', true)
            ->get();

        $missingEan = 0;
        $missingSku = 0;
        $invalidPrices = 0;
        $outOfStock = 0;
        $inShopify = 0;
        $supplierOnly = 0;
        $ambiguous = 0;
        $stale = 0;
        $validOffers = 0;
        $calculator = new PiguSupplierPriceCalculator;

        foreach ($products as $product) {
            $sku = trim((string) ($product->supplier_sku ?? ''));
            $ean = preg_replace('/\D+/', '', (string) ($product->barcode ?? '')) ?? '';
            $price = $calculator->calculate(
                $product->price !== null ? (float) $product->price : null,
                $supplier,
            );
            $stock = (int) ($product->stock_quantity ?? 0);

            if ($sku === '') {
                $missingSku++;
            }
            if ($ean === '' || ! preg_match('/^\d{11,13}$/', $ean)) {
                $missingEan++;
            }
            if ($price === null || $price <= 0) {
                $invalidPrices++;
            }
            if ($stock <= 0) {
                $outOfStock++;
            }
            if ($product->isStale()) {
                $stale++;
            }
            if ($product->match_status === SupplierProduct::MATCH_STATUS_AMBIGUOUS) {
                $ambiguous++;
            }

            if ($product->product_variant_id !== null) {
                $inShopify++;
            } else {
                $supplierOnly++;
            }

            $isExportableSupplierOnly = $product->product_variant_id === null
                && $sku !== ''
                && preg_match('/^\d{11,13}$/', $ean)
                && $price !== null
                && $price > 0
                && $stock > 0
                && ! $product->isStale();

            if ($isExportableSupplierOnly) {
                $validOffers++;
            }
        }

        return [
            'total_products' => $products->count(),
            'valid_offers' => $validOffers,
            'missing_ean' => $missingEan,
            'missing_sku' => $missingSku,
            'invalid_prices' => $invalidPrices,
            'out_of_stock' => $outOfStock,
            'in_shopify' => $inShopify,
            'supplier_only' => $supplierOnly,
            'ambiguous' => $ambiguous,
            'stale' => $stale,
            'estimated_additional_offers' => $validOffers,
        ];
    }

    /**
     * @return Collection<int, array{
     *     supplier: Supplier,
     *     products: int,
     *     valid_offers: int,
     *     last_sync: ?string,
     *     status: string
     * }>
     */
    public function supplierList(): Collection
    {
        return Supplier::query()
            ->where('connector_type', Supplier::CONNECTOR_XML_URL)
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier): array {
                $review = $this->reviewForSupplier($supplier);
                $enabled = PiguSupplierOfferConfig::isEnabled($supplier) && $supplier->enabled;

                $status = match (true) {
                    ! $supplier->enabled => 'išjungtas',
                    ! PiguSupplierOfferConfig::isEnabled($supplier) => 'neaktyvuotas',
                    $supplier->last_sync_status === 'failed' => 'klaida',
                    $enabled && $supplier->last_sync_at !== null => 'aktyvus',
                    default => 'laukiama',
                };

                return [
                    'supplier' => $supplier,
                    'products' => $review['total_products'],
                    'valid_offers' => $review['valid_offers'],
                    'last_sync' => $supplier->last_sync_at?->timezone('Europe/Vilnius')->format('Y-m-d H:i'),
                    'status' => $status,
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $offersFeed = FeedFile::query()
            ->where('filename', 'pigu-offers.xml')
            ->latest('generated_at')
            ->first();

        $lastShopify = SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->latest('finished_at')
            ->first();

        $lastSupplier = Supplier::query()
            ->whereNotNull('last_sync_at')
            ->orderByDesc('last_sync_at')
            ->first();

        $piguSuppliers = Supplier::query()
            ->where('enabled', true)
            ->get()
            ->filter(fn (Supplier $s): bool => PiguSupplierOfferConfig::isEnabled($s));

        $supplierOnlyValid = 0;
        foreach ($piguSuppliers as $supplier) {
            $supplierOnlyValid += $this->reviewForSupplier($supplier)['valid_offers'];
        }

        return [
            'offers_generated_at' => $offersFeed?->generated_at,
            'offers_public_url' => $offersFeed?->public_url ?? 'https://hub.gudle.lt/feeds/pigu-offers.xml',
            'last_shopify_import_at' => $lastShopify?->finished_at,
            'last_supplier_sync_at' => $lastSupplier?->last_sync_at,
            'pigu_supplier_count' => $piguSuppliers->count(),
            'estimated_supplier_only_offers' => $supplierOnlyValid,
            'feed_status' => $offersFeed?->status?->value ?? $offersFeed?->status,
        ];
    }
}
