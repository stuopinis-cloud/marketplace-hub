<?php

namespace App\Services\Marketplace\Varle;

class VarleIssueCodePresenter
{
    /**
     * @var array<string, string>
     */
    private const array LABELS = [
        'missing_barcode' => 'Missing barcode',
        'missing_variant_image' => 'Missing variant image',
        'no_images' => 'No images',
        'no_exportable_variants' => 'No exportable variants',
        'supplier_stock_stale' => 'Supplier stock stale',
        'no_stock_anywhere' => 'No stock anywhere',
        'out_of_stock_no_backorder' => 'Out of stock (no backorder)',
        'backorder_disabled_for_vendor' => 'Backorder disabled for vendor',
        'missing_category_mapping' => 'Missing category mapping',
        'pending_review' => 'Pending review',
        'excluded' => 'Excluded',
        'unpublished' => 'Unpublished',
        'price_invalid' => 'Invalid price',
        'missing_delivery_rule' => 'Missing delivery rule',
        'vendor_disabled_for_varle' => 'Vendor disabled for Varle',
    ];

    /**
     * @var array<string, string>
     */
    private const array COLORS = [
        'missing_barcode' => 'danger',
        'missing_variant_image' => 'warning',
        'no_images' => 'danger',
        'no_exportable_variants' => 'danger',
        'supplier_stock_stale' => 'warning',
        'no_stock_anywhere' => 'warning',
        'out_of_stock_no_backorder' => 'warning',
        'backorder_disabled_for_vendor' => 'warning',
        'missing_category_mapping' => 'warning',
        'pending_review' => 'gray',
        'excluded' => 'gray',
        'unpublished' => 'gray',
        'price_invalid' => 'danger',
        'missing_delivery_rule' => 'warning',
        'vendor_disabled_for_varle' => 'danger',
    ];

    public static function label(string $code): string
    {
        if (isset(self::LABELS[$code])) {
            return self::LABELS[$code];
        }

        return str($code)->replace('_', ' ')->title()->toString();
    }

    public static function color(string $code): string
    {
        return self::COLORS[$code] ?? 'gray';
    }

    /**
     * @param  array<int, string>|null  $codes
     * @return list<array{code: string, label: string, color: string}>
     */
    public static function badges(?array $codes): array
    {
        if ($codes === null || $codes === []) {
            return [];
        }

        return collect($codes)
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->map(fn (string $code): array => [
                'code' => $code,
                'label' => self::label($code),
                'color' => self::color($code),
            ])
            ->all();
    }

    public static function issueCountColor(int $count): string
    {
        return match (true) {
            $count <= 0 => 'success',
            $count <= 2 => 'warning',
            default => 'danger',
        };
    }

    /**
     * @return list<string>
     */
    public static function filterOptions(): array
    {
        $options = [];

        foreach (app(VarleReadinessMetrics::class)->knownIssueCodes() as $code) {
            $options[$code] = self::label($code);
        }

        return $options;
    }
}
