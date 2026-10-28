<?php

namespace App\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use App\Services\Suppliers\SupplierAvailabilityEvaluator;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class SupplierXmlParser
{
    /**
     * Availability labels that must not imply a numeric quantity of 1.
     *
     * @var array<int, string>
     */
    private const array NON_NUMERIC_HOLD_STATUSES = [
        'preorder',
        'pre-order',
        'backorder',
        'back-order',
        'available for order',
    ];

    public function __construct(
        private readonly SupplierAvailabilityEvaluator $availabilityEvaluator = new SupplierAvailabilityEvaluator,
    ) {}

    /**
     * @return array{
     *     entries: array<int, array{
     *         sku: string,
     *         barcode: ?string,
     *         title: ?string,
     *         price: ?float,
     *         currency: ?string,
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
        $this->registerNamespaces($xpath, $document, $supplier);

        $previousXpathErrors = libxml_use_internal_errors(true);
        $items = @$xpath->query($itemPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previousXpathErrors);

        if ($items === false) {
            throw new RuntimeException(
                'XML produktų kelias „'.$itemPath.'“ yra neteisingas. Patikrinkite kelią (pvz. //entry, //product) ir bandykite dar kartą.',
            );
        }

        if ($items->length === 0) {
            throw new RuntimeException(
                'Pasirinktas XML produktų kelias „'.$itemPath.'“ nerado nė vieno produkto. Pasirinkite kitą kelią (pvz. //entry) arba įveskite teisingą XPath.',
            );
        }

        $entries = [];
        $skipped = [];
        $stockPath = SupplierXmlConfig::stockPath($supplier);
        $availabilityPath = SupplierXmlConfig::availabilityPath($supplier);
        $barcodePath = SupplierXmlConfig::barcodePath($supplier);
        $titlePath = SupplierXmlConfig::titlePath($supplier);
        $pricePath = SupplierXmlConfig::pricePath($supplier);

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
            [$quantity, $availabilityStatus] = $this->resolveStock($stockRaw, $availabilityRaw, $supplier);

            $barcode = $barcodePath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $barcodePath, $item))
                : null;
            $title = $titlePath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $titlePath, $item))
                : null;
            $priceRaw = $pricePath !== null
                ? $this->normalizeWhitespace($this->valueAt($xpath, $pricePath, $item))
                : null;
            [$price, $currency] = $this->parsePriceWithCurrency($priceRaw);

            $entries[] = [
                'sku' => $sku,
                'barcode' => $barcode,
                'title' => $title,
                'price' => $price,
                'currency' => $currency,
                'stock_quantity' => $quantity,
                'availability_status' => $availabilityStatus,
                'raw_payload' => array_filter([
                    'sku' => $sku,
                    'stock' => $stockRaw,
                    'availability' => $availabilityRaw ?? ((isset($stockRaw) && ! is_numeric($stockRaw)) ? $stockRaw : null),
                    'barcode' => $barcode,
                    'title' => $title,
                    'price' => $priceRaw,
                    'currency' => $currency,
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
        foreach ($this->fieldQueryCandidates($path) as $candidate) {
            $previous = libxml_use_internal_errors(true);
            $nodes = @$xpath->query($candidate, $context);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

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
     * @return array<int, string>
     */
    private function fieldQueryCandidates(string $path): array
    {
        $candidates = [$path];
        $local = str_contains($path, ':')
            ? (string) explode(':', $path, 2)[1]
            : $path;

        if (! str_contains($path, 'local-name()')) {
            $candidates[] = '*[local-name()="'.$local.'"]';
            $candidates[] = './/*[local-name()="'.$local.'"]';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    public function resolveStock(?string $stockRaw, ?string $availabilityRaw, ?Supplier $supplier = null): array
    {
        if ($stockRaw !== null && $stockRaw !== '' && is_numeric($stockRaw)) {
            $quantity = max(0, (int) $stockRaw);

            return [
                $quantity,
                $quantity > 0
                    ? SupplierProduct::AVAILABILITY_AVAILABLE
                    : SupplierProduct::AVAILABILITY_UNAVAILABLE,
            ];
        }

        $availabilityLabel = $availabilityRaw;
        if (($availabilityLabel === null || $availabilityLabel === '')
            && $stockRaw !== null
            && $stockRaw !== ''
            && ! is_numeric($stockRaw)) {
            $availabilityLabel = $stockRaw;
        }

        $normalized = $this->availabilityEvaluator->normalize($availabilityLabel);

        if ($normalized !== '' && in_array($normalized, self::NON_NUMERIC_HOLD_STATUSES, true)) {
            // Preorder/backorder: do not invent quantity 1; leave unavailable unless explicitly configured later.
            return [0, SupplierProduct::AVAILABILITY_UNAVAILABLE];
        }

        if ($this->availabilityEvaluator->isTruthy($availabilityLabel)) {
            // Null quantity = status-only availability; numeric fallback applied by ProductAvailabilityResolver.
            return [null, SupplierProduct::AVAILABILITY_AVAILABLE];
        }

        if ($this->availabilityEvaluator->isFalsy($availabilityLabel) || $normalized === 'out of stock') {
            return [0, SupplierProduct::AVAILABILITY_UNAVAILABLE];
        }

        return [0, SupplierProduct::AVAILABILITY_UNAVAILABLE];
    }

    /**
     * @return array{0: ?float, 1: ?string}
     */
    public function parsePriceWithCurrency(?string $priceRaw): array
    {
        if ($priceRaw === null || $priceRaw === '') {
            return [null, null];
        }

        $trimmed = trim($priceRaw);
        $currency = null;

        if (preg_match('/\b([A-Za-z]{3})\b/', $trimmed, $currencyMatch) === 1) {
            $currency = mb_strtoupper($currencyMatch[1]);
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = null;
            }
        }

        $amountPart = preg_replace('/[A-Za-z]/', '', $trimmed) ?? '';
        $amountPart = trim(str_replace(["\xc2\xa0", ' '], '', $amountPart));
        $amountPart = str_replace(',', '.', $amountPart);

        if ($amountPart === '' || ! is_numeric($amountPart)) {
            return [null, $currency];
        }

        $price = (float) $amountPart;

        return [$price > 0 ? $price : null, $currency];
    }

    private function registerNamespaces(DOMXPath $xpath, DOMDocument $document, Supplier $supplier): void
    {
        $discovered = (new PiguSupplierXmlFieldDetector)->discoverNamespaces($document);
        $configured = SupplierXmlConfig::namespaces($supplier);

        foreach (array_merge($discovered, $configured) as $prefix => $uri) {
            if (! is_string($prefix) || $prefix === '' || ! is_string($uri) || $uri === '') {
                continue;
            }
            $xpath->registerNamespace($prefix, $uri);
        }
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
