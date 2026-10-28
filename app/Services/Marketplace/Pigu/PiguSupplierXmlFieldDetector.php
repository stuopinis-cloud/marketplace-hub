<?php

namespace App\Services\Marketplace\Pigu;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Autodetects likely XML field paths for Pigu offers-only supplier setup.
 */
class PiguSupplierXmlFieldDetector
{
    /**
     * @return array{
     *     item_path: ?string,
     *     sku_path: ?string,
     *     barcode_path: ?string,
     *     stock_path: ?string,
     *     price_path: ?string,
     *     title_path: ?string,
     *     available_fields: array<int, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    public function detect(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            return $this->emptyResult();
        }

        $xpath = new DOMXPath($document);
        $itemPath = $this->detectItemPath($xpath, $document->documentElement);

        if ($itemPath === null) {
            return $this->emptyResult();
        }

        $items = $xpath->query($itemPath);
        if ($items === false || $items->length === 0) {
            return $this->emptyResult();
        }

        $first = $items->item(0);
        $fields = $first instanceof DOMNode
            ? $this->collectChildFieldNames($first)
            : [];

        $skuPath = $this->pickField($fields, [
            'sku', 'product_sku', 'productsku', 'code', 'product_code', 'id', 'supplier_sku',
        ]);
        $barcodePath = $this->pickField($fields, [
            'ean', 'barcode', 'bar_code', 'gtin', 'upc', 'isbn',
        ]);
        $stockPath = $this->pickField($fields, [
            'stock', 'quantity', 'qty', 'available', 'availability', 'stock_quantity', 'in_stock',
        ]);
        $pricePath = $this->pickField($fields, [
            'price', 'rrp', 'recommended_price', 'retail_price', 'msrp', 'sale_price', 'price_vat',
        ]);
        $titlePath = $this->pickField($fields, [
            'title', 'name', 'product_name', 'product_title', 'description',
        ]);

        $previewRows = [];
        $limit = min(10, $items->length);

        for ($i = 0; $i < $limit; $i++) {
            $item = $items->item($i);
            if (! $item instanceof DOMNode) {
                continue;
            }

            $previewRows[] = [
                'sku' => $skuPath !== null ? $this->childValue($item, $skuPath) : null,
                'ean' => $barcodePath !== null ? $this->childValue($item, $barcodePath) : null,
                'stock' => $stockPath !== null ? $this->childValue($item, $stockPath) : null,
                'price' => $pricePath !== null ? $this->childValue($item, $pricePath) : null,
                'title' => $titlePath !== null ? $this->childValue($item, $titlePath) : null,
            ];
        }

        return [
            'item_path' => $itemPath,
            'sku_path' => $skuPath,
            'barcode_path' => $barcodePath,
            'stock_path' => $stockPath,
            'price_path' => $pricePath,
            'title_path' => $titlePath,
            'available_fields' => $fields,
            'preview_rows' => $previewRows,
            'item_count' => $items->length,
        ];
    }

    /**
     * @return array{
     *     item_path: null,
     *     sku_path: null,
     *     barcode_path: null,
     *     stock_path: null,
     *     price_path: null,
     *     title_path: null,
     *     available_fields: array<int, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    private function emptyResult(): array
    {
        return [
            'item_path' => null,
            'sku_path' => null,
            'barcode_path' => null,
            'stock_path' => null,
            'price_path' => null,
            'title_path' => null,
            'available_fields' => [],
            'preview_rows' => [],
            'item_count' => 0,
        ];
    }

    private function detectItemPath(DOMXPath $xpath, DOMNode $root): ?string
    {
        $candidates = [
            '//product',
            '//item',
            '//offer',
            '//Product',
            '//Item',
            '//Offer',
            '//*[local-name()="product"]',
            '//*[local-name()="item"]',
            '//*[local-name()="offer"]',
        ];

        foreach ($candidates as $candidate) {
            $nodes = $xpath->query($candidate);
            if ($nodes !== false && $nodes->length > 0) {
                return $candidate;
            }
        }

        // Fallback: first repeating child element under root.
        $counts = [];
        foreach ($root->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $name = $child->nodeName;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);
        $best = array_key_first($counts);

        if ($best !== null && ($counts[$best] ?? 0) > 0) {
            return '//'.$best;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function collectChildFieldNames(DOMNode $item): array
    {
        $fields = [];

        foreach ($item->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $local = $child->localName ?: $child->nodeName;
            if ($local !== '' && ! in_array($local, $fields, true)) {
                $fields[] = $local;
            }
        }

        sort($fields);

        return $fields;
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $candidates
     */
    private function pickField(array $fields, array $candidates): ?string
    {
        $lowerMap = [];
        foreach ($fields as $field) {
            $lowerMap[mb_strtolower($field)] = $field;
        }

        foreach ($candidates as $candidate) {
            if (isset($lowerMap[mb_strtolower($candidate)])) {
                return $lowerMap[mb_strtolower($candidate)];
            }
        }

        return null;
    }

    private function childValue(DOMNode $item, string $field): ?string
    {
        foreach ($item->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $local = $child->localName ?: $child->nodeName;
            if (strcasecmp($local, $field) === 0) {
                $value = trim((string) $child->textContent);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
