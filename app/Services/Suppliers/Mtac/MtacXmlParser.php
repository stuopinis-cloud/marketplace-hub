<?php

namespace App\Services\Suppliers\Mtac;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class MtacXmlParser
{
    private const array TRUE_AVAILABILITY = [
        'yes', 'true', 'in_stock', 'available', 'yra', 'sandelyje',
    ];

    private const array FALSE_AVAILABILITY = [
        'no', 'false', 'out_of_stock', 'unavailable', 'nėra', 'nera',
    ];

    /**
     * @return array<int, array{
     *     sku: string,
     *     stock_quantity: int,
     *     availability_status: string,
     *     raw_payload: array<string, mixed>
     * }>
     */
    public function parse(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('M-Tac feed XML is malformed.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('g', 'http://base.google.com/ns/1.0');
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        $entries = $xpath->query('//atom:entry');

        if ($entries === false || $entries->length === 0) {
            $entries = $xpath->query('//entry');
        }

        if ($entries === false || $entries->length === 0) {
            throw new RuntimeException('M-Tac feed XML did not contain any entry nodes.');
        }

        $parsed = [];

        foreach ($entries as $entry) {
            if (! $entry instanceof DOMNode) {
                continue;
            }

            $sku = $this->normalizeWhitespace($this->firstValue($xpath, [
                ['g:SKU', $entry],
                ['SKU', $entry],
                ['*[local-name()="SKU"]', $entry],
            ]));

            if ($sku === null || $sku === '') {
                continue;
            }

            $stockRaw = $this->normalizeWhitespace($this->firstValue($xpath, [
                ['g:stock', $entry],
                ['stock', $entry],
                ['*[local-name()="stock"]', $entry],
            ]));

            $availabilityRaw = $this->normalizeWhitespace($this->firstValue($xpath, [
                ['g:availability', $entry],
                ['availability', $entry],
                ['*[local-name()="availability"]', $entry],
            ]));

            [$quantity, $availabilityStatus] = $this->resolveStock($stockRaw, $availabilityRaw);

            $parsed[] = [
                'sku' => $sku,
                'stock_quantity' => $quantity,
                'availability_status' => $availabilityStatus,
                'raw_payload' => array_filter([
                    'sku' => $sku,
                    'stock' => $stockRaw,
                    'availability' => $availabilityRaw,
                ], fn ($value): bool => $value !== null && $value !== ''),
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<int, array{0: string, 1: DOMNode}>  $queries
     */
    private function firstValue(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as [$query, $context]) {
            $nodes = $xpath->query($query, $context);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = trim((string) $nodes->item(0)?->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function resolveStock(?string $stockRaw, ?string $availabilityRaw): array
    {
        if ($stockRaw !== null && $stockRaw !== '' && is_numeric($stockRaw)) {
            $quantity = max(0, (int) $stockRaw);

            return [
                $quantity,
                $quantity > 0
                    ? 'available'
                    : 'unavailable',
            ];
        }

        if ($this->isTruthyAvailability($availabilityRaw)) {
            return [1, 'available'];
        }

        return [0, 'unavailable'];
    }

    public function isTruthyAvailability(?string $value): bool
    {
        $normalized = mb_strtolower(trim((string) $value));

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::TRUE_AVAILABILITY, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_AVAILABILITY, true)) {
            return false;
        }

        return false;
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized === '' ? null : $normalized;
    }
}
