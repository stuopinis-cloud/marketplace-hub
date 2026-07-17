<?php

namespace App\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierAvailabilityEvaluator;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class SupplierXmlParser
{
    public function __construct(
        private readonly SupplierAvailabilityEvaluator $availabilityEvaluator = new SupplierAvailabilityEvaluator,
    ) {}

    /**
     * @return array{
     *     entries: array<int, array{
     *         sku: string,
     *         stock_quantity: ?int,
     *         availability_status: string,
     *         raw_payload: array<string, mixed>
     *     }>,
     *     skipped: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $xml, Supplier $supplier): array
    {
        $itemPath = SupplierXmlConfig::itemPath($supplier);
        $skuPath = SupplierXmlConfig::skuPath($supplier);

        if ($itemPath === null || $skuPath === null) {
            throw new RuntimeException('XML item path and SKU path mapping are required.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Supplier feed XML is malformed.');
        }

        $xpath = new DOMXPath($document);

        foreach (SupplierXmlConfig::namespaces($supplier) as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        $items = $xpath->query($itemPath);

        if ($items === false || $items->length === 0) {
            throw new RuntimeException('XML feed did not contain any items for path: '.$itemPath);
        }

        $entries = [];
        $skipped = [];
        $stockPath = SupplierXmlConfig::stockPath($supplier);
        $availabilityPath = SupplierXmlConfig::availabilityPath($supplier);
        $barcodePath = SupplierXmlConfig::barcodePath($supplier);
        $titlePath = SupplierXmlConfig::titlePath($supplier);

        foreach ($items as $index => $item) {
            if (! $item instanceof DOMNode) {
                continue;
            }

            $sku = $this->normalizeWhitespace($this->valueAt($xpath, $skuPath, $item));

            if ($sku === null || $sku === '') {
                $skipped[] = [
                    'sku' => '—',
                    'issue_code' => 'missing_sku',
                    'message' => 'Missing SKU on XML item '.((int) $index + 1).'.',
                ];

                continue;
            }

            $stockRaw = $stockPath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $stockPath, $item))
                : null;
            $availabilityRaw = $availabilityPath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $availabilityPath, $item))
                : null;
            [$quantity, $availabilityStatus] = $this->resolveStock($stockRaw, $availabilityRaw);

            $barcode = $barcodePath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $barcodePath, $item))
                : null;
            $title = $titlePath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $titlePath, $item))
                : null;

            $entries[] = [
                'sku' => $sku,
                'stock_quantity' => $quantity,
                'availability_status' => $availabilityStatus,
                'raw_payload' => array_filter([
                    'sku' => $sku,
                    'stock' => $stockRaw,
                    'availability' => $availabilityRaw,
                    'barcode' => $barcode,
                    'title' => $title,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
        }

        return [
            'entries' => $entries,
            'skipped' => $skipped,
        ];
    }

    private function valueAt(DOMXPath $xpath, string $path, DOMNode $context): ?string
    {
        $candidates = [$path];

        if (! str_contains($path, ':') && ! str_contains($path, 'local-name()')) {
            $candidates[] = '*[local-name()="'.$path.'"]';
            $candidates[] = './/*[local-name()="'.$path.'"]';
        }

        foreach ($candidates as $candidate) {
            $nodes = $xpath->query($candidate, $context);

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
     * @return array{0: ?int, 1: string}
     */
    public function resolveStock(?string $stockRaw, ?string $availabilityRaw): array
    {
        if ($stockRaw !== null && $stockRaw !== '' && is_numeric($stockRaw)) {
            $quantity = max(0, (int) $stockRaw);

            return [
                $quantity,
                $quantity > 0 ? 'available' : 'unavailable',
            ];
        }

        if ($this->availabilityEvaluator->isTruthy($availabilityRaw)) {
            return [null, 'available'];
        }

        return [0, 'unavailable'];
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
