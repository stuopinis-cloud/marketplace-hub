<?php

namespace App\Services\Suppliers;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierSkuMatcher
{
    public const string STRATEGY_SCOPED_DEFAULT = 'scoped_default';

    public const string STRATEGY_SKU_GLOBAL = 'sku_global';

    /**
     * @param  array<int, string>  $vendorScope
     */
    public function __construct(
        private readonly array $vendorScope = [],
        private readonly string $matchingStrategy = self::STRATEGY_SCOPED_DEFAULT,
        private readonly bool $matchByBarcode = true,
    ) {}

    public static function forSupplier(Supplier $supplier, ?array $vendorScope = null): self
    {
        $strategy = SupplierCsvConfig::matchingStrategy($supplier);
        $scope = $vendorScope ?? SupplierCsvConfig::vendorScope($supplier);

        return new self(
            vendorScope: $scope,
            matchingStrategy: $strategy,
            matchByBarcode: SupplierCsvConfig::matchByBarcode($supplier),
        );
    }

    /**
     * @param  Collection<int, ProductVariant>  $shopifyVariants
     * @param  Collection<int, SupplierProduct>  $existingMappings
     * @return array{
     *     variant: ?ProductVariant,
     *     match_status: string,
     *     match_method: ?string,
     *     issue_code: ?string
     * }
     */
    public function match(
        string $supplierSku,
        Collection $shopifyVariants,
        Collection $existingMappings,
        ?string $barcode = null,
    ): array {
        $normalizedSku = $this->normalizeSku($supplierSku);

        $manual = $existingMappings->first(function (SupplierProduct $mapping) use ($normalizedSku): bool {
            if ($mapping->product_variant_id === null) {
                return false;
            }

            return $this->normalizeSku($mapping->supplier_sku) === $normalizedSku
                && $mapping->match_method === SupplierProduct::MATCH_METHOD_MANUAL;
        });

        if ($manual instanceof SupplierProduct && $manual->productVariant instanceof ProductVariant) {
            return [
                'variant' => $manual->productVariant,
                'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
                'match_method' => SupplierProduct::MATCH_METHOD_MANUAL,
                'issue_code' => null,
            ];
        }

        if ($this->matchingStrategy === self::STRATEGY_SKU_GLOBAL) {
            return $this->matchSkuGlobal($normalizedSku, $shopifyVariants, $barcode);
        }

        return $this->matchScopedDefault($normalizedSku, $shopifyVariants, $barcode);
    }

    /**
     * @param  Collection<int, ProductVariant>  $shopifyVariants
     * @return array{
     *     variant: ?ProductVariant,
     *     match_status: string,
     *     match_method: ?string,
     *     issue_code: ?string
     * }
     */
    private function matchSkuGlobal(string $normalizedSku, Collection $shopifyVariants, ?string $barcode): array
    {
        if ($this->matchByBarcode && filled($barcode)) {
            $barcodeMatch = $this->matchByBarcode($shopifyVariants, $barcode);

            if ($barcodeMatch !== null) {
                return $barcodeMatch;
            }
        }

        $matches = $shopifyVariants
            ->filter(fn (ProductVariant $variant): bool => $this->normalizeSku((string) $variant->sku) === $normalizedSku)
            ->values();

        if ($matches->count() > 1) {
            return [
                'variant' => null,
                'match_status' => SupplierProduct::MATCH_STATUS_AMBIGUOUS,
                'match_method' => null,
                'issue_code' => 'duplicate_shopify_sku',
            ];
        }

        if ($matches->count() === 1) {
            return [
                'variant' => $matches->first(),
                'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
                'match_method' => SupplierProduct::MATCH_METHOD_SKU_GLOBAL,
                'issue_code' => null,
            ];
        }

        return [
            'variant' => null,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'match_method' => null,
            'issue_code' => null,
        ];
    }

    /**
     * @param  Collection<int, ProductVariant>  $shopifyVariants
     * @return array{
     *     variant: ?ProductVariant,
     *     match_status: string,
     *     match_method: ?string,
     *     issue_code: ?string
     * }
     */
    private function matchScopedDefault(string $normalizedSku, Collection $shopifyVariants, ?string $barcode): array
    {
        if ($this->matchByBarcode && filled($barcode)) {
            $barcodeMatch = $this->matchByBarcode($shopifyVariants, $barcode);

            if ($barcodeMatch !== null) {
                return $barcodeMatch;
            }
        }

        $matches = $shopifyVariants
            ->filter(fn (ProductVariant $variant): bool => $this->normalizeSku((string) $variant->sku) === $normalizedSku)
            ->values();

        if ($matches->count() > 1) {
            return [
                'variant' => null,
                'match_status' => SupplierProduct::MATCH_STATUS_AMBIGUOUS,
                'match_method' => null,
                'issue_code' => 'duplicate_shopify_sku',
            ];
        }

        if ($matches->count() === 1) {
            return [
                'variant' => $matches->first(),
                'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
                'match_method' => SupplierProduct::MATCH_METHOD_SKU,
                'issue_code' => null,
            ];
        }

        return [
            'variant' => null,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'match_method' => null,
            'issue_code' => null,
        ];
    }

    /**
     * @param  Collection<int, ProductVariant>  $shopifyVariants
     * @return array{
     *     variant: ?ProductVariant,
     *     match_status: string,
     *     match_method: ?string,
     *     issue_code: ?string
     * }|null
     */
    private function matchByBarcode(Collection $shopifyVariants, string $barcode): ?array
    {
        $normalizedBarcode = $this->normalizeSku($barcode);
        $barcodeMatches = $shopifyVariants
            ->filter(fn (ProductVariant $variant): bool => $this->normalizeSku((string) $variant->barcode) === $normalizedBarcode)
            ->values();

        if ($barcodeMatches->count() > 1) {
            return [
                'variant' => null,
                'match_status' => SupplierProduct::MATCH_STATUS_AMBIGUOUS,
                'match_method' => null,
                'issue_code' => 'duplicate_shopify_sku',
            ];
        }

        if ($barcodeMatches->count() === 1) {
            return [
                'variant' => $barcodeMatches->first(),
                'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
                'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
                'issue_code' => null,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array{sku: string}>  $entries
     * @return array<string, int>
     */
    public function duplicateSupplierSkus(array $entries): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $normalized = $this->normalizeSku($entry['sku']);
            $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        }

        return array_filter($counts, fn (int $count): bool => $count > 1);
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function loadShopifyVariants(): Collection
    {
        if ($this->matchingStrategy === self::STRATEGY_SKU_GLOBAL) {
            return ProductVariant::query()
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->with('product')
                ->get();
        }

        $normalizedVendors = collect($this->vendorScope)
            ->map(fn (string $vendor): string => mb_strtolower(trim($vendor)))
            ->filter()
            ->values()
            ->all();

        if ($normalizedVendors === []) {
            return collect();
        }

        return ProductVariant::query()
            ->whereHas('product', function ($query) use ($normalizedVendors): void {
                $query->whereIn(DB::raw('LOWER(TRIM(vendor))'), $normalizedVendors);
            })
            ->with('product')
            ->get();
    }

    public function matchingStrategy(): string
    {
        return $this->matchingStrategy;
    }

    public function normalizeSku(?string $sku): string
    {
        return mb_strtolower(trim((string) $sku));
    }
}
