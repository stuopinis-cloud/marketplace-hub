<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\MarketplaceTranslation;
use App\Models\PiguCategoryField;
use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PiguProductFeedExporter
{
    /** @var list<array<string, mixed>> */
    private array $skippedRows = [];

    /** @var list<array{product_id: int, handle: ?string, title: ?string, chosen_source_handle: ?string, chosen_category_id: ?string, easy_only_allowed: bool}> */
    private array $exportResolutions = [];

    /** @var array<string, int> */
    private array $seenBarcodes = [];

    /** @var array<string, int> */
    private array $seenSupplierCodes = [];

    public function __construct(
        private readonly PiguReadinessService $readiness,
        private readonly PiguDescriptionSanitizer $sanitizer,
        private readonly PiguRuleResolver $rules = new PiguRuleResolver,
        private readonly PiguImageUrlNormalizer $images = new PiguImageUrlNormalizer,
    ) {}

    /**
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     absolute_path: string,
     *     public_url: string,
     *     products_considered: int,
     *     exported_products: int,
     *     exported_modifications: int,
     *     skipped_products: int,
     *     skipped_modifications: int,
     *     skipped_by_code: array<string, int>,
     *     skipped_by_source_category: array<string, int>,
     *     filter: array{easy_only: bool, mapped_only: bool, collection: ?string},
     *     skipped_rows: list<array<string, mixed>>,
     *     export_resolutions: list<array{product_id: int, handle: ?string, title: ?string, chosen_source_handle: ?string, chosen_category_id: ?string, easy_only_allowed: bool}>
     * }
     */
    public function export(?PiguExportOptions $options = null): array
    {
        $options ??= new PiguExportOptions;
        $this->skippedRows = [];
        $this->exportResolutions = [];
        $this->seenBarcodes = [];
        $this->seenSupplierCodes = [];

        $channel = $this->resolveChannel();
        $config = $this->channelConfig($channel);
        $relativePath = (string) $config['feed_path'];
        $tempPath = (string) $config['feed_temp_path'];

        $easyEligibleHandles = $options->easyOnly
            ? $this->easyOnlyCollectionHandles($channel)
            : null;

        $disk = Storage::disk('public');
        $disk->makeDirectory('feeds');
        $disk->delete($tempPath);

        $tempAbsolute = $disk->path($tempPath);
        $finalAbsolute = $disk->path($relativePath);

        $productsConsidered = 0;
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

            $this->productQuery($channel, $options, $easyEligibleHandles)
                ->chunkById((int) $config['export_chunk_size'], function ($products) use (
                    $handle,
                    $channel,
                    $config,
                    $easyEligibleHandles,
                    &$productsConsidered,
                    &$exportedProducts,
                    &$exportedModifications,
                    &$skippedProducts,
                    &$skippedModifications,
                ): void {
                    foreach ($products as $product) {
                        $productsConsidered++;
                        $result = $this->exportProduct($product, $channel, $config, $easyEligibleHandles);

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
            'products_considered' => $productsConsidered,
            'exported_products' => $exportedProducts,
            'exported_modifications' => $exportedModifications,
            'skipped_products' => $skippedProducts,
            'skipped_modifications' => $skippedModifications,
            'skipped_by_code' => $this->groupSkippedByCode(),
            'skipped_by_source_category' => $this->groupSkippedBySourceCategory(),
            'filter' => [
                'easy_only' => $options->easyOnly,
                'mapped_only' => $options->mappedOnly,
                'collection' => $options->collection,
            ],
            'skipped_rows' => $this->skippedRows,
            'export_resolutions' => $this->exportResolutions,
            'warnings' => [
                'Pigu product XML does not include prices. Confirm separate offer/price/stock feed requirements before going live.',
            ],
        ];
    }

    /**
     * @param  list<string>|null  $easyEligibleHandles
     * @return Builder<Product>
     */
    private function productQuery(
        MarketplaceChannel $channel,
        PiguExportOptions $options,
        ?array $easyEligibleHandles = null,
    ): Builder {
        $query = Product::query()
            ->with(['variants.inventoryLevels', 'variants.supplierProducts.supplier', 'images', 'sourceCategories'])
            ->orderBy('id');

        if ($options->collection !== null) {
            $handle = $options->collection;
            $query->whereHas('sourceCategories', function (Builder $builder) use ($handle): void {
                $builder->where('handle', $handle);
            });
        }

        if ($options->easyOnly) {
            $handles = $easyEligibleHandles ?? $this->easyOnlyCollectionHandles($channel);

            if ($handles === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('sourceCategories', function (Builder $builder) use ($handles): void {
                    $builder->where('type', 'collection')->whereIn('handle', $handles);
                });
            }
        } elseif ($options->mappedOnly) {
            $this->applyMappedOnlyFilter($query, $channel);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public function easyOnlyCollectionHandles(MarketplaceChannel $channel): array
    {
        $mappedHandles = CategoryMapping::query()
            ->where('marketplace_channel_id', $channel->id)
            ->where('source_type', 'collection')
            ->where('enabled', true)
            ->where('export_enabled', true)
            ->pluck('source_value')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $dimensionHandles = PiguDimensionRule::query()
            ->where('enabled', true)
            ->where('match_type', 'collection')
            ->pluck('match_value')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique();

        $propertyHandles = PiguPropertyRule::query()
            ->where('enabled', true)
            ->where('match_type', 'collection')
            ->pluck('match_value')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique();

        return $mappedHandles
            ->filter(fn (string $handle): bool => $dimensionHandles->contains($handle) && $propertyHandles->contains($handle))
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyMappedOnlyFilter(Builder $query, MarketplaceChannel $channel): void
    {
        $mappings = CategoryMapping::query()
            ->where('marketplace_channel_id', $channel->id)
            ->where('enabled', true)
            ->where('export_enabled', true)
            ->get(['source_type', 'source_value']);

        if ($mappings->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('sourceCategories', function (Builder $builder) use ($mappings): void {
            $builder->where(function (Builder $outer) use ($mappings): void {
                foreach ($mappings as $mapping) {
                    $value = trim((string) $mapping->source_value);

                    if ($value === '') {
                        continue;
                    }

                    $outer->orWhere(function (Builder $inner) use ($mapping, $value): void {
                        $inner->where('type', $mapping->source_type)
                            ->where(function (Builder $match) use ($value): void {
                                $match->where('handle', $value)
                                    ->orWhere('name', $value)
                                    ->orWhere('external_id', $value);
                            });
                    });
                }
            });
        });
    }

    public function resolveChannel(): MarketplaceChannel
    {
        $defaults = [
            'feed_path' => config('marketplace.exports.pigu.feed_path', 'feeds/pigu-products.xml'),
            'require_category_mapping' => config('marketplace.exports.pigu.require_category_mapping', true),
            'allow_fallback_product_images' => config('marketplace.exports.pigu.allow_fallback_product_images', true),
        ];

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            [
                'name' => 'Pigu / PHH',
                'enabled' => true,
                'config' => $defaults,
            ],
        );

        if (! $channel->enabled) {
            $channel->update(['enabled' => true]);
            $channel->refresh();
        }

        return $channel;
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
     * @param  list<string>|null  $easyEligibleHandles
     * @return array{
     *     xml_blocks: list<string>,
     *     exported_modifications: int,
     *     skipped_products: int,
     *     skipped_modifications: int
     * }
     */
    private function exportProduct(
        Product $product,
        MarketplaceChannel $channel,
        array $config,
        ?array $easyEligibleHandles = null,
    ): array {
        $readiness = $this->readiness->analyzeProduct($product, $channel, $easyEligibleHandles);
        $chosenSourceHandle = $readiness['chosen_source_handle'] ?? null;
        $easyOnlyAllowed = $easyEligibleHandles !== null;

        $this->exportResolutions[] = [
            'product_id' => $product->id,
            'handle' => $product->handle,
            'title' => $product->title,
            'chosen_source_handle' => $chosenSourceHandle,
            'chosen_category_id' => $readiness['category_id'] ?? null,
            'easy_only_allowed' => $easyOnlyAllowed,
        ];

        if (! $readiness['exportable']) {
            foreach ($readiness['issues'] as $issue) {
                $this->skip(
                    $product,
                    null,
                    $issue['code'],
                    $issue['message'],
                    $issue['severity'] ?? 'error',
                    $readiness['debug'] ?? [],
                );
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
        $preferredForRules = $easyOnlyAllowed && is_string($chosenSourceHandle)
            ? $chosenSourceHandle
            : null;

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

        $groups = PiguVariantGrouping::buildGroups($product, $validVariants);

        $blocks = [];
        $exportedMods = 0;

        foreach ($groups as $group) {
            $built = $this->buildProductXml(
                $product,
                $group['split_value'],
                $group['variants'],
                $categoryId,
                $categoryName,
                $config,
                $preferredForRules,
                $readiness['manufacturer_name'] ?? null,
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
     * @param  list<array{variant: ProductVariant, quantity: int}>  $variants
     * @param  array<string, mixed>  $config
     * @return array{xml: string, modifications: int}|null
     */
    private function buildProductXml(
        Product $product,
        ?string $splitValue,
        array $variants,
        string $categoryId,
        string $categoryName,
        array $config,
        ?string $preferredSourceHandle = null,
        ?string $manufacturerName = null,
    ): ?array {
        $title = PiguVariantGrouping::appendSplitValueToTitle((string) $product->title, $splitValue);

        $description = $this->sanitizer->sanitize((string) ($product->description_html ?? ''));
        if ($description === '') {
            $this->skip($product, null, 'missing_long_description', 'Sanitized long description is empty.');

            return null;
        }

        $imageResult = VarleVariantPresenter::resolveExportImageUrls($product, $variants, $config);
        $normalizedImages = $this->images->normalizeMany($imageResult['urls']);

        foreach ($normalizedImages['warnings'] as $warning) {
            $this->skip(
                $product,
                $variants[0]['variant'] ?? null,
                $warning['reason'],
                'Skipped image URL due to format/protocol: '.$warning['url'],
                'warning',
            );
        }

        $httpsImages = $normalizedImages['urls'];

        if ($httpsImages === []) {
            $hadHttpOnly = collect($imageResult['urls'])->contains(
                fn (string $url): bool => str_starts_with(strtolower($url), 'http://'),
            );

            if ($hadHttpOnly) {
                $this->skip($product, $variants[0]['variant'] ?? null, 'image_not_https', 'Only HTTP image URLs found.');
            } elseif (collect($imageResult['urls'])->contains(fn (string $url): bool => $this->images->isWebpUrl($url))) {
                $this->skip(
                    $product,
                    $variants[0]['variant'] ?? null,
                    'invalid_image_format_webp',
                    'No valid HTTPS JPG/JPEG/PNG images available (WEBP only).',
                );
            } else {
                $this->skip($product, $variants[0]['variant'] ?? null, 'missing_image', 'No HTTPS JPG/JPEG/PNG images available.');
            }

            return null;
        }

        $modificationsXml = '';
        $modificationCount = 0;
        $multipleMods = count($variants) > 1
            || (count($variants) === 1 && PiguVariantGrouping::getModificationOptions($product, $variants[0]['variant']) !== []);

        foreach ($variants as $row) {
            $mod = $this->buildModificationXml(
                $product,
                $row['variant'],
                $multipleMods,
                $config,
                $preferredSourceHandle,
            );

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
            $enTitle = PiguVariantGrouping::appendSplitValueToTitle($titleEn, $splitValue);
            $xml .= '    <title-en>'.$this->cdata($enTitle)."</title-en>\n";
        }

        $xml .= '    <long-description>'.$this->cdata($description)."</long-description>\n";

        if ($descriptionEn !== null) {
            $xml .= '    <long-description-en>'.$this->cdata($this->sanitizer->sanitize($descriptionEn))."</long-description-en>\n";
        }

        $resolvedManufacturer = filled($manufacturerName)
            ? $manufacturerName
            : ($config['manufacturer_name'] ?? null);

        foreach ([
            'manufacturer-name' => $resolvedManufacturer,
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

        $xml .= $this->buildPropertiesXml($product, $categoryId, $splitValue, $preferredSourceHandle);
        $xml .= "    <colours>\n";
        $xml .= "      <colour>\n";

        if ($splitValue !== null && $splitValue !== '') {
            $xml .= '        <colour-title>'.$this->cdata($splitValue)."</colour-title>\n";
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
        ?string $preferredSourceHandle = null,
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

        $dimensions = $this->resolveDimensions($variant, $config, $preferredSourceHandle);
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
            $modificationTitle = PiguVariantGrouping::modificationTitle($product, $variant);
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
    private function resolveDimensions(
        ProductVariant $variant,
        array $config,
        ?string $preferredSourceHandle = null,
    ): array {
        $variant->loadMissing('product.sourceCategories');
        $product = $variant->product;
        $ruleDimensions = $product !== null
            ? $this->rules->resolveDimensions($product, $preferredSourceHandle)
            : null;

        if ($ruleDimensions !== null) {
            $weightKg = $this->weightToKg($variant);
            $usedFallbackWeight = $weightKg === null || $weightKg <= 0;

            return [
                'weight' => $usedFallbackWeight ? $ruleDimensions['weight_kg'] : $weightKg,
                'length' => $ruleDimensions['length_m'],
                'height' => $ruleDimensions['height_m'],
                'width' => $ruleDimensions['width_m'],
                'used_fallback' => false,
            ];
        }

        $weightKg = $this->weightToKg($variant);
        $usedFallback = false;

        if ($weightKg === null || $weightKg <= 0) {
            $weightKg = (float) $config['default_weight_kg'];
            $usedFallback = true;
        }

        $length = (float) $config['default_length_m'];
        $height = (float) $config['default_height_m'];
        $width = (float) $config['default_width_m'];
        $usedFallback = true;

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

    private function buildPropertiesXml(
        Product $product,
        string $categoryId,
        ?string $colorValue,
        ?string $preferredSourceHandle = null,
    ): string {
        $ruleProperties = $this->rules->resolveProperties($product, $categoryId, $preferredSourceHandle);

        if ($ruleProperties !== []) {
            $xml = "    <properties>\n";

            foreach ($ruleProperties as $property) {
                $xmlId = $this->propertyXmlIdFromRule($property);
                $xml .= "      <property>\n";
                $xml .= '        <id>'.$this->cdata($xmlId)."</id>\n";
                $xml .= "        <values>\n";
                $xml .= '          <value>'.$this->cdata($property['value'])."</value>\n";
                $xml .= "        </values>\n";
                $xml .= "      </property>\n";
            }

            $xml .= "    </properties>\n";

            return $xml;
        }

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

        $xml = "    <properties>\n";
        $written = 0;

        foreach ($properties as $key => $value) {
            $id = $this->resolvePropertyXmlId($categoryId, $key);
            $xml .= "      <property>\n";
            $xml .= '        <id>'.$this->cdata($id)."</id>\n";
            $xml .= "        <values>\n";
            $xml .= '          <value>'.$this->cdata($value)."</value>\n";
            $xml .= "        </values>\n";
            $xml .= "      </property>\n";
            $written++;
        }

        $first = $product->variants->first();
        if ($first !== null) {
            $size = collect(VarleVariantPresenter::getMeaningfulNonColorOptions($product, $first))
                ->first(fn (array $option): bool => VarleVariantPresenter::isSizeOptionName($option['name']));
            if (is_array($size)) {
                $xml .= "      <property>\n";
                $xml .= '        <id>'.$this->cdata($this->resolvePropertyXmlId($categoryId, 'size'))."</id>\n";
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
     * @param  array{property_name: string, property_id?: ?int, value: string}  $property
     */
    private function propertyXmlIdFromRule(array $property): string
    {
        $name = trim((string) ($property['property_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $id = $property['property_id'] ?? null;

        return $id !== null ? (string) $id : '';
    }

    /**
     * PHH XML expects the human property name in <id>, not the numeric Pigu field_id.
     * Prefer an explicit property_name; only fall back to a numeric id when no name is available.
     */
    private function resolvePropertyXmlId(string $categoryId, string $propertyName): string
    {
        $trimmed = trim($propertyName);

        if ($trimmed !== '' && ! ctype_digit($trimmed)) {
            $field = $this->findCategoryField($categoryId, $trimmed);

            if ($field !== null) {
                $title = trim((string) ($field->field_title ?: $field->field_title_en ?: ''));

                if ($title !== '') {
                    return $title;
                }
            }

            return $trimmed;
        }

        $field = $trimmed !== ''
            ? $this->findCategoryField($categoryId, $trimmed)
            : null;

        if ($field !== null) {
            $title = trim((string) ($field->field_title ?: $field->field_title_en ?: ''));

            if ($title !== '') {
                return $title;
            }

            if ($field->field_id !== null) {
                return (string) $field->field_id;
            }
        }

        return $trimmed !== '' ? $trimmed : $propertyName;
    }

    private function findCategoryField(string $categoryId, string $propertyName): ?PiguCategoryField
    {
        $normalized = mb_strtolower($propertyName);

        return PiguCategoryField::query()
            ->where('lowest_category_id', (int) $categoryId)
            ->get()
            ->first(function (PiguCategoryField $candidate) use ($normalized, $propertyName): bool {
                $titleEn = mb_strtolower((string) ($candidate->field_title_en ?: ''));
                $titleLt = mb_strtolower((string) ($candidate->field_title ?: ''));
                $fieldId = (string) ($candidate->field_id ?? '');

                return $titleEn === $normalized
                    || $titleLt === $normalized
                    || $titleLt === mb_strtolower($propertyName)
                    || $fieldId === $propertyName
                    || str_contains($titleEn, $normalized)
                    || str_contains($titleLt, $normalized);
            });
    }

    /**
     * @return array<string, int>
     */
    private function groupSkippedByCode(): array
    {
        return collect($this->skippedRows)
            ->groupBy(fn (array $row): string => (string) ($row['code'] ?? 'unknown'))
            ->map(fn ($rows): int => $rows->count())
            ->sortDesc()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function groupSkippedBySourceCategory(): array
    {
        $counts = [];

        foreach ($this->skippedRows as $row) {
            $categories = $row['source_categories'] ?? ($row['debug']['source_categories'] ?? []);

            if (! is_array($categories) || $categories === []) {
                $counts['none'] = ($counts['none'] ?? 0) + 1;

                continue;
            }

            $keys = [];

            foreach ($categories as $category) {
                if (! is_array($category)) {
                    continue;
                }

                $key = filled($category['handle'] ?? null)
                    ? (string) $category['handle']
                    : (filled($category['name'] ?? null) ? (string) $category['name'] : null);

                if ($key === null || $key === '') {
                    continue;
                }

                $keys[$key] = true;
            }

            if ($keys === []) {
                $counts['none'] = ($counts['none'] ?? 0) + 1;

                continue;
            }

            foreach (array_keys($keys) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
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

        fputcsv($handle, [
            'product_id',
            'handle',
            'title',
            'variant_id',
            'sku',
            'barcode',
            'code',
            'message',
            'severity',
            'marketplace_channel_id',
            'mapping_matched',
            'source_categories',
        ]);

        foreach ($this->skippedRows as $row) {
            $categories = $row['source_categories'] ?? ($row['debug']['source_categories'] ?? []);

            fputcsv($handle, [
                $row['product_id'],
                $row['handle'] ?? null,
                $row['title'] ?? null,
                $row['variant_id'],
                $row['sku'],
                $row['barcode'],
                $row['code'],
                $row['message'],
                $row['severity'],
                $row['debug']['marketplace_channel_id'] ?? null,
                isset($row['debug']['mapping_matched']) ? (($row['debug']['mapping_matched'] ? 'yes' : 'no')) : null,
                $this->formatSourceCategoriesForCsv(is_array($categories) ? $categories : []),
            ]);
        }

        fclose($handle);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     */
    private function formatSourceCategoriesForCsv(array $categories): string
    {
        return collect($categories)
            ->map(function (array $category): string {
                return sprintf(
                    '%s:%s/%s/%s',
                    $category['type'] ?? '',
                    $category['handle'] ?? '',
                    $category['name'] ?? '',
                    $category['external_id'] ?? '',
                );
            })
            ->implode('; ');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceCategoriesPayload(Product $product): array
    {
        $product->loadMissing('sourceCategories');

        return $product->sourceCategories->map(fn ($category): array => [
            'id' => $category->id,
            'type' => $category->type,
            'name' => $category->name,
            'handle' => $category->handle,
            'external_id' => $category->external_id,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    private function skip(
        Product $product,
        ?ProductVariant $variant,
        string $code,
        string $message,
        string $severity = 'error',
        array $debug = [],
    ): void {
        $sourceCategories = $debug['source_categories'] ?? $this->sourceCategoriesPayload($product);
        $debug['source_categories'] = $sourceCategories;

        $this->skippedRows[] = [
            'product_id' => $product->id,
            'handle' => $product->handle,
            'title' => $product->title,
            'variant_id' => $variant?->id,
            'sku' => $variant?->sku,
            'barcode' => $variant?->barcode,
            'code' => $code,
            'message' => $message,
            'severity' => $severity,
            'source_categories' => $sourceCategories,
            'debug' => $debug,
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
