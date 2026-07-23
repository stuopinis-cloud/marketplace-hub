<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\MarketplaceChannel;
use App\Models\MarketplaceTranslation;
use App\Models\PiguCategoryField;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PiguProductFeedExporter
{
    /** @var list<array<string, mixed>> */
    private array $skippedRows = [];

    /** @var array<string, int> */
    private array $seenBarcodes = [];

    /** @var array<string, int> */
    private array $seenSupplierCodes = [];

    public function __construct(
        private readonly PiguReadinessService $readiness,
        private readonly PiguDescriptionSanitizer $sanitizer,
    ) {}

    /**
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     absolute_path: string,
     *     public_url: string,
     *     exported_products: int,
     *     exported_modifications: int,
     *     skipped_products: int,
     *     skipped_modifications: int,
     *     skipped_rows: list<array<string, mixed>>
     * }
     */
    public function export(): array
    {
        $this->skippedRows = [];
        $this->seenBarcodes = [];
        $this->seenSupplierCodes = [];

        $channel = $this->resolveChannel();
        $config = $this->channelConfig($channel);
        $relativePath = (string) $config['feed_path'];
        $tempPath = (string) $config['feed_temp_path'];

        $disk = Storage::disk('public');
        $disk->makeDirectory('feeds');
        $disk->delete($tempPath);

        $tempAbsolute = $disk->path($tempPath);
        $finalAbsolute = $disk->path($relativePath);

        $exportedProducts = 0;
        $exportedModifications = 0;
        $skippedProducts = 0;
        $skippedModifications = 0;

        $handle = fopen($tempAbsolute, 'w');

        if ($handle === false) {
            throw new RuntimeException('Unable to open Pigu feed temp file for writing.');
        }

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($handle, '<products xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'."\n");

            Product::query()
                ->with(['variants.inventoryLevels', 'variants.supplierProducts.supplier', 'images', 'sourceCategories'])
                ->orderBy('id')
                ->chunkById((int) $config['export_chunk_size'], function ($products) use (
                    $handle,
                    $channel,
                    $config,
                    &$exportedProducts,
                    &$exportedModifications,
                    &$skippedProducts,
                    &$skippedModifications,
                ): void {
                    foreach ($products as $product) {
                        $result = $this->exportProduct($product, $channel, $config);

                        foreach ($result['xml_blocks'] as $block) {
                            fwrite($handle, $block);
                            $exportedProducts++;
                        }

                        $exportedModifications += $result['exported_modifications'];
                        $skippedProducts += $result['skipped_products'];
                        $skippedModifications += $result['skipped_modifications'];
                    }
                });

            fwrite($handle, '</products>'."\n");
        } finally {
            fclose($handle);
        }

        $xml = file_get_contents($tempAbsolute);

        if ($xml === false || @simplexml_load_string($xml) === false) {
            File::delete($tempAbsolute);
            throw new RuntimeException('Generated Pigu feed is not well-formed XML.');
        }

        File::ensureDirectoryExists(dirname($finalAbsolute), 0755);
        File::replace($finalAbsolute, $xml);
        File::delete($tempAbsolute);

        $this->writeSkippedCsv($config);

        $publicUrl = (string) (
            $config['public_url']
            ?: url('/feeds/'.basename($relativePath))
        );

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'absolute_path' => storage_path('app/public/'.$relativePath),
            'public_url' => $publicUrl,
            'exported_products' => $exportedProducts,
            'exported_modifications' => $exportedModifications,
            'skipped_products' => $skippedProducts,
            'skipped_modifications' => $skippedModifications,
            'skipped_rows' => $this->skippedRows,
        ];
    }

    public function resolveChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu', 'name' => 'Pigu'],
            [
                'enabled' => true,
                'config' => [
                    'feed_path' => config('marketplace.exports.pigu.feed_path', 'feeds/pigu-products.xml'),
                    'require_category_mapping' => config('marketplace.exports.pigu.require_category_mapping', true),
                    'allow_fallback_product_images' => config('marketplace.exports.pigu.allow_fallback_product_images', true),
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function channelConfig(MarketplaceChannel $channel): array
    {
        $defaults = config('marketplace.exports.pigu', []);

        return array_merge($defaults, is_array($channel->config) ? $channel->config : []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     xml_blocks: list<string>,
     *     exported_modifications: int,
     *     skipped_products: int,
     *     skipped_modifications: int
     * }
     */
    private function exportProduct(Product $product, MarketplaceChannel $channel, array $config): array
    {
        $readiness = $this->readiness->analyzeProduct($product, $channel);

        if (! $readiness['exportable']) {
            foreach ($readiness['issues'] as $issue) {
                $this->skip($product, null, $issue['code'], $issue['message']);
            }

            return [
                'xml_blocks' => [],
                'exported_modifications' => 0,
                'skipped_products' => 1,
                'skipped_modifications' => 0,
            ];
        }

        $categoryId = (string) $readiness['category_id'];
        $categoryName = (string) $readiness['category_name'];

        $validVariants = [];
        $skippedMods = 0;

        foreach ($product->variants as $variant) {
            $availability = $this->readiness->variantAvailability($variant);

            if (! ($config['export_zero_stock'] ?? false) && ! $availability['exportable']) {
                $this->skip(
                    $product,
                    $variant,
                    $availability['issue_code'] ?? 'quantity_not_exportable',
                    'Variant quantity is not exportable.',
                );
                $skippedMods++;

                continue;
            }

            $validVariants[] = [
                'variant' => $variant,
                'quantity' => $availability['quantity'],
            ];
        }

        if ($validVariants === []) {
            $this->skip($product, null, 'quantity_not_exportable', 'No exportable variants.');

            return [
                'xml_blocks' => [],
                'exported_modifications' => 0,
                'skipped_products' => 1,
                'skipped_modifications' => $skippedMods,
            ];
        }

        $hasColor = VarleVariantPresenter::productHasColorOption($product);
        $groups = $this->buildColourGroups($product, $validVariants, $hasColor);

        $blocks = [];
        $exportedMods = 0;

        foreach ($groups as $group) {
            $built = $this->buildProductXml(
                $product,
                $group['color_value'],
                $group['variants'],
                $categoryId,
                $categoryName,
                $config,
            );

            if ($built === null) {
                $skippedMods += count($group['variants']);

                continue;
            }

            $blocks[] = $built['xml'];
            $exportedMods += $built['modifications'];
        }

        return [
            'xml_blocks' => $blocks,
            'exported_modifications' => $exportedMods,
            'skipped_products' => $blocks === [] ? 1 : 0,
            'skipped_modifications' => $skippedMods,
        ];
    }

    /**
     * @param  list<array{variant: ProductVariant, quantity: int}>  $validVariants
     * @return list<array{color_value: ?string, variants: list<array{variant: ProductVariant, quantity: int}>}>
     */
    private function buildColourGroups(Product $product, array $validVariants, bool $hasColor): array
    {
        if (! $hasColor) {
            return [['color_value' => null, 'variants' => $validVariants]];
        }

        return collect($validVariants)
            ->filter(fn (array $row): bool => filled(VarleVariantPresenter::colorValue($product, $row['variant'])))
            ->groupBy(fn (array $row): string => (string) VarleVariantPresenter::colorValue($product, $row['variant']))
            ->map(fn ($rows, string $color): array => [
                'color_value' => $color,
                'variants' => $rows->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{variant: ProductVariant, quantity: int}>  $variants
     * @param  array<string, mixed>  $config
     * @return array{xml: string, modifications: int}|null
     */
    private function buildProductXml(
        Product $product,
        ?string $colorValue,
        array $variants,
        string $categoryId,
        string $categoryName,
        array $config,
    ): ?array {
        $title = trim((string) $product->title);
        if ($colorValue !== null && $colorValue !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($colorValue))) {
            $title .= ', '.$colorValue;
        }

        $description = $this->sanitizer->sanitize((string) ($product->description_html ?? ''));
        if ($description === '') {
            $this->skip($product, null, 'missing_long_description', 'Sanitized long description is empty.');

            return null;
        }

        $imageResult = VarleVariantPresenter::resolveExportImageUrls($product, $variants, $config);
        $httpsImages = [];
        $hadHttp = false;

        foreach ($imageResult['urls'] as $url) {
            if (str_starts_with(strtolower($url), 'https://')) {
                $httpsImages[] = $url;
            } elseif (str_starts_with(strtolower($url), 'http://')) {
                $hadHttp = true;
            }
        }

        if ($hadHttp && $httpsImages === []) {
            $this->skip($product, $variants[0]['variant'] ?? null, 'image_not_https', 'Only HTTP image URLs found.');

            return null;
        }

        if ($httpsImages === []) {
            $this->skip($product, $variants[0]['variant'] ?? null, 'missing_image', 'No HTTPS images available.');

            return null;
        }

        $modificationsXml = '';
        $modificationCount = 0;
        $multipleMods = count($variants) > 1
            || (count($variants) === 1 && VarleVariantPresenter::getMeaningfulNonColorOptions($product, $variants[0]['variant']) !== []);

        foreach ($variants as $row) {
            $mod = $this->buildModificationXml($product, $row['variant'], $multipleMods, $config);

            if ($mod === null) {
                continue;
            }

            $modificationsXml .= $mod;
            $modificationCount++;
        }

        if ($modificationCount === 0) {
            $this->skip($product, null, 'missing_barcode', 'No exportable modifications for colour group.');

            return null;
        }

        $titleEn = $this->readiness->usableEnglishTranslation($product, MarketplaceTranslation::FIELD_TITLE);
        $descriptionEn = $this->readiness->usableEnglishTranslation($product, MarketplaceTranslation::FIELD_DESCRIPTION);

        $xml = "  <product>\n";
        $xml .= '    <category-id>'.$this->esc($categoryId)."</category-id>\n";
        $xml .= '    <category-name>'.$this->cdata($categoryName)."</category-name>\n";
        $xml .= '    <title>'.$this->cdata($title)."</title>\n";

        if ($titleEn !== null) {
            $enTitle = $titleEn;
            if ($colorValue !== null && $colorValue !== '' && ! str_contains(mb_strtolower($enTitle), mb_strtolower($colorValue))) {
                $enTitle .= ', '.$colorValue;
            }
            $xml .= '    <title-en>'.$this->cdata($enTitle)."</title-en>\n";
        }

        $xml .= '    <long-description>'.$this->cdata($description)."</long-description>\n";

        if ($descriptionEn !== null) {
            $xml .= '    <long-description-en>'.$this->cdata($this->sanitizer->sanitize($descriptionEn))."</long-description-en>\n";
        }

        foreach ([
            'manufacturer-name' => $config['manufacturer_name'] ?? null,
            'manufacturer-address' => $config['manufacturer_address'] ?? null,
            'manufacturer-email' => $config['manufacturer_email'] ?? null,
            'representative-name' => $config['representative_name'] ?? null,
            'representative-address' => $config['representative_address'] ?? null,
            'representative-email' => $config['representative_email'] ?? null,
        ] as $tag => $value) {
            if (filled($value)) {
                $xml .= '    <'.$tag.'>'.$this->cdata((string) $value).'</'.$tag.">\n";
            }
        }

        $xml .= $this->buildPropertiesXml($product, $categoryId, $colorValue);
        $xml .= "    <colours>\n";
        $xml .= "      <colour>\n";

        if ($colorValue !== null && $colorValue !== '') {
            $xml .= '        <colour-title>'.$this->cdata($colorValue)."</colour-title>\n";
        }

        $xml .= "        <images>\n";
        foreach ($httpsImages as $url) {
            $xml .= "          <image>\n";
            $xml .= '            <url>'.$this->esc($url)."</url>\n";
            $xml .= "          </image>\n";
        }
        $xml .= "        </images>\n";
        $xml .= "        <modifications>\n";
        $xml .= $modificationsXml;
        $xml .= "        </modifications>\n";
        $xml .= "      </colour>\n";
        $xml .= "    </colours>\n";
        $xml .= "  </product>\n";

        return ['xml' => $xml, 'modifications' => $modificationCount];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildModificationXml(
        Product $product,
        ProductVariant $variant,
        bool $multipleMods,
        array $config,
    ): ?string {
        $barcode = preg_replace('/\D+/', '', (string) ($variant->barcode ?? '')) ?? '';

        if ($barcode === '') {
            $this->skip($product, $variant, 'missing_barcode', 'Barcode is missing.');

            return null;
        }

        if (! preg_match('/^\d{11,13}$/', $barcode)) {
            $this->skip($product, $variant, 'invalid_barcode', 'Barcode must be 11–13 digits.');

            return null;
        }

        if (isset($this->seenBarcodes[$barcode])) {
            $this->skip($product, $variant, 'duplicate_barcode', "Duplicate barcode {$barcode}.");

            return null;
        }

        $supplierCode = trim((string) ($variant->sku ?? ''));

        if ($supplierCode === '') {
            $this->skip($product, $variant, 'missing_supplier_code', 'Supplier code / SKU is missing.');

            return null;
        }

        if (isset($this->seenSupplierCodes[$supplierCode])) {
            $strategy = (string) ($config['duplicate_sku_strategy'] ?? 'append_variant_id');

            if ($strategy === 'skip') {
                $this->skip($product, $variant, 'duplicate_supplier_code', "Duplicate supplier-code {$supplierCode}.");

                return null;
            }

            $supplierCode = $supplierCode.'-'.$variant->id;
        }

        $this->seenBarcodes[$barcode] = $variant->id;
        $this->seenSupplierCodes[$supplierCode] = $variant->id;

        $dimensions = $this->resolveDimensions($variant, $config);
        if ($dimensions['used_fallback']) {
            $this->skip(
                $product,
                $variant,
                'fallback_dimensions_used',
                'Fallback weight/dimensions used for Pigu export.',
                'warning',
            );
        }

        $modificationTitle = '';
        if ($multipleMods) {
            $modificationTitle = VarleVariantPresenter::variantDisplayTitle($product, $variant);
            if (VarleVariantPresenter::isDefaultShopifyOptionValue($modificationTitle)) {
                $modificationTitle = '';
            }
        }

        $xml = "          <modification>\n";
        $xml .= '            <modification-title>'.($modificationTitle !== '' ? $this->cdata($modificationTitle) : '')."</modification-title>\n";
        $xml .= '            <weight>'.$this->decimal($dimensions['weight'])."</weight>\n";
        $xml .= '            <length>'.$this->decimal($dimensions['length'])."</length>\n";
        $xml .= '            <height>'.$this->decimal($dimensions['height'])."</height>\n";
        $xml .= '            <width>'.$this->decimal($dimensions['width'])."</width>\n";
        $xml .= "            <attributes>\n";
        $xml .= "              <barcodes>\n";
        $xml .= '                <barcode>'.$this->cdata($barcode)."</barcode>\n";
        $xml .= "              </barcodes>\n";
        $xml .= '              <supplier-code>'.$this->cdata($supplierCode)."</supplier-code>\n";

        $manufacturerCode = trim((string) ($variant->sku ?? ''));
        if ($manufacturerCode !== '' && $manufacturerCode !== $supplierCode) {
            $xml .= '              <manufacturer-code>'.$this->cdata($manufacturerCode)."</manufacturer-code>\n";
        }

        $xml .= "            </attributes>\n";
        $xml .= "          </modification>\n";

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{weight: float, length: float, height: float, width: float, used_fallback: bool}
     */
    private function resolveDimensions(ProductVariant $variant, array $config): array
    {
        $weightKg = $this->weightToKg($variant);
        $usedFallback = false;

        if ($weightKg === null || $weightKg <= 0) {
            $weightKg = (float) $config['default_weight_kg'];
            $usedFallback = true;
        }

        // Package dimensions are not on Shopify variants yet — use configured defaults.
        $length = (float) $config['default_length_m'];
        $height = (float) $config['default_height_m'];
        $width = (float) $config['default_width_m'];
        $usedFallback = true; // dimensions always fall back for MVP

        return [
            'weight' => $weightKg,
            'length' => $length,
            'height' => $height,
            'width' => $width,
            'used_fallback' => $usedFallback,
        ];
    }

    private function weightToKg(ProductVariant $variant): ?float
    {
        if ($variant->weight === null) {
            return null;
        }

        $weight = (float) $variant->weight;
        $unit = mb_strtolower((string) ($variant->weight_unit ?? 'kg'));

        return match ($unit) {
            'g', 'gram', 'grams' => $weight / 1000,
            'lb', 'lbs', 'pound', 'pounds' => $weight * 0.45359237,
            'oz', 'ounce', 'ounces' => $weight * 0.0283495231,
            default => $weight,
        };
    }

    private function buildPropertiesXml(Product $product, string $categoryId, ?string $colorValue): string
    {
        $properties = [];

        $brand = filled($product->vendor) ? (string) $product->vendor : (filled($product->brand) ? (string) $product->brand : null);
        if ($brand !== null) {
            $properties['brand'] = $brand;
        }

        if ($colorValue !== null && $colorValue !== '') {
            $properties['color'] = $colorValue;
        }

        if (filled($product->product_type)) {
            $properties['product_type'] = (string) $product->product_type;
        }

        $material = $this->readiness->extractMaterial($product);
        if ($material !== null) {
            $properties['material'] = $material;
        }

        // Prefer field_id from imported category fields when titles match.
        $fieldIds = PiguCategoryField::query()
            ->where('lowest_category_id', (int) $categoryId)
            ->get()
            ->keyBy(fn (PiguCategoryField $field): string => mb_strtolower((string) ($field->field_title_en ?: $field->field_title)));

        $xml = "    <properties>\n";
        $written = 0;

        foreach ($properties as $key => $value) {
            $field = $fieldIds->first(
                fn (PiguCategoryField $candidate): bool => str_contains(
                    mb_strtolower((string) ($candidate->field_title_en ?: $candidate->field_title)),
                    match ($key) {
                        'brand' => 'brand',
                        'color' => 'color',
                        'product_type' => 'type',
                        'material' => 'material',
                        default => $key,
                    },
                ) || str_contains(
                    mb_strtolower((string) ($candidate->field_title ?: '')),
                    match ($key) {
                        'brand' => 'ženklas',
                        'color' => 'spalva',
                        'product_type' => 'tipas',
                        'material' => 'medžiaga',
                        default => $key,
                    },
                ),
            );

            $id = $field?->field_id !== null ? (string) $field->field_id : $key;
            $xml .= "      <property>\n";
            $xml .= '        <id>'.$this->cdata($id)."</id>\n";
            $xml .= "        <values>\n";
            $xml .= '          <value>'.$this->cdata($value)."</value>\n";
            $xml .= "        </values>\n";
            $xml .= "      </property>\n";
            $written++;
        }

        // Size property from first variant when present.
        $first = $product->variants->first();
        if ($first !== null) {
            $size = collect(VarleVariantPresenter::getMeaningfulNonColorOptions($product, $first))
                ->first(fn (array $option): bool => VarleVariantPresenter::isSizeOptionName($option['name']));
            if (is_array($size)) {
                $xml .= "      <property>\n";
                $xml .= '        <id>'.$this->cdata('size')."</id>\n";
                $xml .= "        <values>\n";
                $xml .= '          <value>'.$this->cdata((string) $size['value'])."</value>\n";
                $xml .= "        </values>\n";
                $xml .= "      </property>\n";
                $written++;
            }
        }

        if ($written === 0) {
            return '';
        }

        $xml .= "    </properties>\n";

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeSkippedCsv(array $config): void
    {
        $relative = (string) ($config['failed_csv_path'] ?? 'exports/pigu_failed_latest.csv');
        $disk = Storage::disk('public');
        $disk->makeDirectory(dirname($relative));

        $handle = fopen($disk->path($relative), 'w');

        if ($handle === false) {
            return;
        }

        fputcsv($handle, ['product_id', 'variant_id', 'sku', 'barcode', 'code', 'message', 'severity']);

        foreach ($this->skippedRows as $row) {
            fputcsv($handle, [
                $row['product_id'],
                $row['variant_id'],
                $row['sku'],
                $row['barcode'],
                $row['code'],
                $row['message'],
                $row['severity'],
            ]);
        }

        fclose($handle);
    }

    private function skip(
        Product $product,
        ?ProductVariant $variant,
        string $code,
        string $message,
        string $severity = 'error',
    ): void {
        $this->skippedRows[] = [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'sku' => $variant?->sku,
            'barcode' => $variant?->barcode,
            'code' => $code,
            'message' => $message,
            'severity' => $severity,
        ];
    }

    private function cdata(string $value): string
    {
        return '<![CDATA['.$this->sanitizer->cdataSafe($value).']]>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function decimal(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
