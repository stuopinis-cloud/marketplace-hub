<?php

namespace App\Services\Marketplace\Pigu;

use XMLReader;

/**
 * Lightweight streaming index of offers XML (sku => stock/hours/price/ean).
 */
class PiguOfferFeedIndex
{
    /**
     * @return array<string, array{sku: string, ean: string, stock: int, collectionhours: int, price: string}>
     */
    public function loadFromFile(?string $absolutePath): array
    {
        if ($absolutePath === null || $absolutePath === '' || ! is_file($absolutePath)) {
            return [];
        }

        $reader = new XMLReader;
        if (@$reader->open($absolutePath) !== true) {
            return [];
        }

        $index = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'product') {
                    continue;
                }

                $inner = $reader->readOuterXml();
                if ($inner === '' || $inner === false) {
                    continue;
                }

                $sku = $this->cdataValue($inner, 'sku');
                if ($sku === '') {
                    continue;
                }

                $index[$sku] = [
                    'sku' => $sku,
                    'ean' => $this->cdataValue($inner, 'ean'),
                    'stock' => (int) $this->plainValue($inner, 'stock'),
                    'collectionhours' => (int) $this->plainValue($inner, 'collectionhours'),
                    'price' => $this->plainValue($inner, 'price-after-discount'),
                ];
            }
        } finally {
            $reader->close();
        }

        return $index;
    }

    private function cdataValue(string $xml, string $tag): string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'><!\[CDATA\[(.*?)\]\]><\/'.preg_quote($tag, '/').'>/s', $xml, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return '';
    }

    private function plainValue(string $xml, string $tag): string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return '';
    }
}
