<?php

namespace App\Services\Suppliers\Mtac;

use App\Models\ProductVariant;
use App\Models\SupplierProduct;
use Illuminate\Support\Collection;

class MtacSkuMatcher
{
    public const string VENDOR = 'M-Tac';

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
    ): array {
        $normalizedSku = $this->normalizeSku($supplierSku);

        $manual = $existingMappings->first(function (SupplierProduct $mapping) use ($supplierSku, $normalizedSku): bool {
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

    public function normalizeSku(?string $sku): string
    {
        return mb_strtolower(trim((string) $sku));
    }
}
