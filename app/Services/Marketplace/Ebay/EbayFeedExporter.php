<?php

namespace App\Services\Marketplace\Ebay;

use App\Models\MarketplaceChannel;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

class EbayFeedExporter
{
    public function __construct(
        private readonly MarketplaceTranslationService $translations = new MarketplaceTranslationService,
    ) {}

    /**
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     exported_products: int,
     *     exported_variants: int,
     *     skipped_products: int
     * }
     */
    public function export(string $locale = 'en'): array
    {
        $channel = $this->resolveChannel();
        $marketplace = 'ebay';
        $currency = (string) data_get($channel->config, 'currency', config('marketplace.exports.ebay.currency', 'EUR'));
        $relativePath = (string) data_get($channel->config, 'feed_path', config('marketplace.exports.ebay.feed_path', 'feeds/ebay-en.xml'));
        $requiresApproved = (bool) data_get(
            $channel->config,
            'requires_approved_translations',
            config('marketplace.exports.ebay.requires_approved_translations', false),
        );

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ebayFeed/>');
        $xml->addChild('marketplace', 'ebay');
        $xml->addChild('locale', $locale);
        $xml->addChild('currency', $currency);
        $productsNode = $xml->addChild('products');

        $exportedProducts = 0;
        $exportedVariants = 0;
        $skippedProducts = 0;

        Product::query()
            ->with(['variants', 'images'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use (
                $productsNode,
                $marketplace,
                $locale,
                $requiresApproved,
                &$exportedProducts,
                &$exportedVariants,
                &$skippedProducts,
            ): void {
                foreach ($products as $product) {
                    if (! $this->shouldExportProduct($product, $marketplace, $locale, $requiresApproved)) {
                        $skippedProducts++;

                        continue;
                    }

                    $productNode = $productsNode->addChild('product');
                    $productNode->addChild('id', (string) $product->id);
                    $productNode->addChild('handle', (string) ($product->handle ?? ''));
                    $this->addCdata($productNode, 'title', $this->translatedProductField(
                        $product,
                        MarketplaceTranslation::FIELD_TITLE,
                        (string) $product->title,
                        $locale,
                        $marketplace,
                    ));
                    $this->addCdata($productNode, 'description', $this->translatedProductField(
                        $product,
                        MarketplaceTranslation::FIELD_DESCRIPTION,
                        (string) ($product->description_html ?? ''),
                        $locale,
                        $marketplace,
                    ));
                    $productNode->addChild('brand', (string) ($product->vendor ?? $product->brand ?? ''));
                    $productNode->addChild('vendor', (string) ($product->vendor ?? ''));

                    $variantsNode = $productNode->addChild('variants');

                    foreach ($product->variants as $variant) {
                        $variantNode = $variantsNode->addChild('variant');
                        $variantNode->addChild('id', (string) $variant->id);
                        $variantNode->addChild('sku', (string) ($variant->sku ?? ''));
                        $variantNode->addChild('barcode', (string) ($variant->barcode ?? ''));
                        $variantNode->addChild('price', (string) ($variant->price ?? ''));
                        $this->appendTranslatedOptions($variantNode, $variant, $locale, $marketplace);
                        $exportedVariants++;
                    }

                    $exportedProducts++;
                }
            });

        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relativePath));
        $tempPath = (string) config('marketplace.exports.ebay.feed_temp_path', $relativePath.'.tmp');
        $disk->put($tempPath, $xml->asXML() ?: '');
        $disk->move($tempPath, $relativePath);

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'exported_products' => $exportedProducts,
            'exported_variants' => $exportedVariants,
            'skipped_products' => $skippedProducts,
        ];
    }

    public function resolveChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'ebay', 'name' => 'eBay'],
            [
                'enabled' => true,
                'config' => [
                    'locale' => config('marketplace.exports.ebay.locale', 'en'),
                    'currency' => config('marketplace.exports.ebay.currency', 'EUR'),
                    'feed_path' => config('marketplace.exports.ebay.feed_path', 'feeds/ebay-en.xml'),
                    'requires_approved_translations' => config('marketplace.exports.ebay.requires_approved_translations', false),
                ],
            ],
        );
    }

    private function shouldExportProduct(
        Product $product,
        string $marketplace,
        string $locale,
        bool $requiresApproved,
    ): bool {
        if ($requiresApproved) {
            $title = $this->translations->getTranslation(
                $product,
                MarketplaceTranslation::FIELD_TITLE,
                $locale,
                (string) $product->title,
                $marketplace,
            );

            return $title?->status === \App\Enums\MarketplaceTranslationStatus::Approved;
        }

        return filled($product->title);
    }

    private function translatedProductField(
        Product $product,
        string $field,
        string $source,
        string $locale,
        string $marketplace,
    ): string {
        return $this->translations->applyTranslationOrFallback(
            $product,
            $field,
            $locale,
            $source,
            $marketplace,
        );
    }

    private function appendTranslatedOptions(
        SimpleXMLElement $variantNode,
        ProductVariant $variant,
        string $locale,
        string $marketplace,
    ): void {
        $optionsNode = $variantNode->addChild('options');

        for ($index = 1; $index <= 3; $index++) {
            $name = trim((string) ($variant->{"option{$index}_name"} ?? ''));
            $value = trim((string) ($variant->{"option{$index}_value"} ?? $variant->{"option{$index}"} ?? ''));

            if ($name === '' && $value === '') {
                continue;
            }

            $optionNode = $optionsNode->addChild('option');
            $optionNode->addChild('position', (string) $index);
            $this->addCdata(
                $optionNode,
                'name',
                $name === ''
                    ? ''
                    : $this->translations->applyTranslationOrFallback(
                        $variant,
                        MarketplaceTranslation::FIELD_OPTION_NAME.':'.$index,
                        $locale,
                        $name,
                        $marketplace,
                    ),
            );
            $this->addCdata(
                $optionNode,
                'value',
                $value === ''
                    ? ''
                    : $this->translations->applyTranslationOrFallback(
                        $variant,
                        MarketplaceTranslation::FIELD_OPTION_VALUE.':'.$index,
                        $locale,
                        $value,
                        $marketplace,
                    ),
            );
        }
    }

    private function addCdata(SimpleXMLElement $parent, string $name, string $value): void
    {
        $child = $parent->addChild($name);
        $node = dom_import_simplexml($child);
        $owner = $node->ownerDocument;

        if ($owner === null) {
            return;
        }

        $node->appendChild($owner->createCDATASection($value));
    }
}
