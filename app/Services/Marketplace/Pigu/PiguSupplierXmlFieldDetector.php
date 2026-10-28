<?php

namespace App\Services\Marketplace\Pigu;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Autodetects likely XML field paths for Pigu offers-only supplier setup.
 * Supports Atom / Google Merchant namespaces (default Atom + g:).
 */
class PiguSupplierXmlFieldDetector
{
    /**
     * @var array<int, string>
     */
    private const array PRODUCT_LIKE_NAMES = [
        'product',
        'item',
        'offer',
        'entry',
        'article',
        'goods',
        'position',
        'row',
        'variant',
    ];

    /**
     * @var array<string, string>
     */
    private const array WELL_KNOWN_NAMESPACES = [
        'atom' => 'http://www.w3.org/2005/Atom',
        'g' => 'http://base.google.com/ns/1.0',
    ];

    /**
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
     *     sku_path: ?string,
     *     barcode_path: ?string,
     *     stock_path: ?string,
     *     availability_path: ?string,
     *     price_path: ?string,
     *     title_path: ?string,
     *     available_fields: array<int, string>,
     *     namespaces: array<string, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    public function detect(string $xml): array
    {
        [$document, $xpath, $namespaces] = $this->loadDocument($xml);
        if ($document === null || $xpath === null) {
            return $this->emptyResult();
        }

        $candidates = $this->discoverItemPathCandidates($xpath, $document->documentElement);
        $itemPath = $candidates[0]['path'] ?? null;

        if ($itemPath === null) {
            return $this->emptyResult($namespaces);
        }

        return $this->buildResultForPath($xpath, $itemPath, $candidates, $namespaces);
    }

    /**
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
     *     sku_path: ?string,
     *     barcode_path: ?string,
     *     stock_path: ?string,
     *     availability_path: ?string,
     *     price_path: ?string,
     *     title_path: ?string,
     *     available_fields: array<int, string>,
     *     namespaces: array<string, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    public function detectForItemPath(string $xml, string $itemPath, array $candidates = []): array
    {
        [$document, $xpath, $namespaces] = $this->loadDocument($xml);
        if ($document === null || $xpath === null) {
            return $this->emptyResult();
        }

        $itemPath = trim($itemPath);
        if ($itemPath === '') {
            return $this->emptyResult($namespaces);
        }

        if ($candidates === []) {
            $candidates = $this->discoverItemPathCandidates($xpath, $document->documentElement);
        }

        $count = $this->countPath($xpath, $itemPath);
        $candidates = $this->mergeCandidate($candidates, $itemPath, $count);

        return $this->buildResultForPath($xpath, $itemPath, $candidates, $namespaces);
    }

    public function countPath(DOMXPath $xpath, string $path): int
    {
        $previous = libxml_use_internal_errors(true);
        $nodes = @$xpath->query($path);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($nodes === false) {
            return 0;
        }

        return $nodes->length;
    }

    /**
     * @return array{0: ?DOMDocument, 1: ?DOMXPath, 2: array<string, string>}
     */
    private function loadDocument(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            return [null, null, []];
        }

        $namespaces = $this->discoverNamespaces($document);
        $xpath = new DOMXPath($document);
        $this->registerNamespaces($xpath, $namespaces);

        return [$document, $xpath, $namespaces];
    }

    /**
     * @param  array<string, string>  $namespaces
     * @return array{
     *     item_path: null,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
     *     sku_path: null,
     *     barcode_path: null,
     *     stock_path: null,
     *     availability_path: null,
     *     price_path: null,
     *     title_path: null,
     *     available_fields: array<int, string>,
     *     namespaces: array<string, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    private function emptyResult(array $namespaces = []): array
    {
        return [
            'item_path' => null,
            'item_path_candidates' => [],
            'sku_path' => null,
            'barcode_path' => null,
            'stock_path' => null,
            'availability_path' => null,
            'price_path' => null,
            'title_path' => null,
            'available_fields' => [],
            'namespaces' => $namespaces,
            'preview_rows' => [],
            'item_count' => 0,
        ];
    }

    /**
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     * @param  array<string, string>  $namespaces
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
     *     sku_path: ?string,
     *     barcode_path: ?string,
     *     stock_path: ?string,
     *     availability_path: ?string,
     *     price_path: ?string,
     *     title_path: ?string,
     *     available_fields: array<int, string>,
     *     namespaces: array<string, string>,
     *     preview_rows: array<int, array<string, string|null>>,
     *     item_count: int
     * }
     */
    private function buildResultForPath(DOMXPath $xpath, string $itemPath, array $candidates, array $namespaces): array
    {
        $items = @$xpath->query($itemPath);
        if ($items === false || $items->length === 0) {
            return [
                'item_path' => $itemPath,
                'item_path_candidates' => $candidates,
                'sku_path' => null,
                'barcode_path' => null,
                'stock_path' => null,
                'availability_path' => null,
                'price_path' => null,
                'title_path' => null,
                'available_fields' => [],
                'namespaces' => $namespaces,
                'preview_rows' => [],
                'item_count' => 0,
            ];
        }

        $first = $items->item(0);
        $fields = $first instanceof DOMNode
            ? $this->collectChildFieldNames($first)
            : [];

        $skuPath = $this->pickField($fields, [
            'g:id', 'id', 'sku', 'product_sku', 'productsku', 'code', 'product_code', 'supplier_sku', 'mpn', 'g:sku', 'SKU',
        ]);
        $barcodePath = $this->pickField($fields, [
            'g:gtin', 'gtin', 'ean', 'barcode', 'bar_code', 'upc', 'isbn', 'g:ean',
        ]);
        $availabilityPath = $this->pickField($fields, [
            'g:availability', 'availability', 'available',
        ]);
        $numericStockPath = $this->pickField($fields, [
            'g:stock', 'stock', 'quantity', 'qty', 'stock_quantity', 'in_stock', 'g:quantity',
        ]);
        // Prefer numeric stock; fall back to availability string field for Merchant feeds.
        $stockPath = $numericStockPath ?? $availabilityPath;
        $pricePath = $this->pickField($fields, [
            'g:price', 'price', 'rrp', 'recommended_price', 'retail_price', 'msrp', 'sale_price', 'price_vat',
        ]);
        $titlePath = $this->pickField($fields, [
            'g:title', 'title', 'name', 'product_name', 'product_title', 'description',
        ]);

        $previewRows = [];
        $limit = min(10, $items->length);

        for ($i = 0; $i < $limit; $i++) {
            $item = $items->item($i);
            if (! $item instanceof DOMNode) {
                continue;
            }

            $previewRows[] = [
                'sku' => $skuPath !== null ? $this->childValue($xpath, $item, $skuPath) : null,
                'ean' => $barcodePath !== null ? $this->childValue($xpath, $item, $barcodePath) : null,
                'stock' => $stockPath !== null ? $this->childValue($xpath, $item, $stockPath) : null,
                'price' => $pricePath !== null ? $this->childValue($xpath, $item, $pricePath) : null,
                'title' => $titlePath !== null ? $this->childValue($xpath, $item, $titlePath) : null,
            ];
        }

        return [
            'item_path' => $itemPath,
            'item_path_candidates' => $candidates,
            'sku_path' => $skuPath,
            'barcode_path' => $barcodePath,
            'stock_path' => $stockPath,
            'availability_path' => $availabilityPath,
            'price_path' => $pricePath,
            'title_path' => $titlePath,
            'available_fields' => $fields,
            'namespaces' => $namespaces,
            'preview_rows' => $previewRows,
            'item_count' => $items->length,
        ];
    }

    /**
     * @return array<int, array{path: string, count: int, label: string}>
     */
    private function discoverItemPathCandidates(DOMXPath $xpath, DOMElement $root): array
    {
        /** @var array<string, array{path: string, count: int, score: int}> $byPath */
        $byPath = [];

        foreach (self::PRODUCT_LIKE_NAMES as $name) {
            foreach (['//'.$name, '//*[local-name()="'.$name.'"]'] as $path) {
                $count = $this->countPath($xpath, $path);
                if ($count <= 0) {
                    continue;
                }
                $this->addCandidateScore($byPath, $path, $count, $this->scoreCandidate($path, $count, $xpath, $name));
            }
        }

        $allElements = $xpath->query('//*');
        if ($allElements !== false) {
            /** @var array<string, array{count: int, sample: ?DOMElement}> $byLocal */
            $byLocal = [];

            foreach ($allElements as $element) {
                if (! $element instanceof DOMElement || $element->isSameNode($root)) {
                    continue;
                }

                $local = $element->localName ?: $element->nodeName;
                if ($local === '') {
                    continue;
                }

                if (! isset($byLocal[$local])) {
                    $byLocal[$local] = ['count' => 0, 'sample' => $element];
                }
                $byLocal[$local]['count']++;
            }

            foreach ($byLocal as $local => $meta) {
                $count = $meta['count'];
                if ($count < 1) {
                    continue;
                }

                $sample = $meta['sample'];
                $path = ($sample instanceof DOMElement && filled($sample->namespaceURI))
                    ? '//*[local-name()="'.$local.'"]'
                    : '//'.$local;

                $this->addCandidateScore(
                    $byPath,
                    $path,
                    $count,
                    $this->scoreCandidate($path, $count, $xpath, $local),
                );
            }
        }

        uasort($byPath, fn (array $a, array $b): int => [$b['score'], $b['count']] <=> [$a['score'], $a['count']]);

        $candidates = [];
        foreach ($byPath as $row) {
            if ($row['count'] <= 0) {
                continue;
            }
            $candidates[] = [
                'path' => $row['path'],
                'count' => $row['count'],
                'label' => $this->candidateLabel($row['path'], $row['count']),
            ];
        }

        return array_values($candidates);
    }

    /**
     * @return array<string, string>
     */
    public function discoverNamespaces(DOMDocument $document): array
    {
        $namespaces = self::WELL_KNOWN_NAMESPACES;
        $root = $document->documentElement;

        if ($root === null) {
            return $namespaces;
        }

        if (filled($root->namespaceURI)) {
            $prefix = filled($root->prefix) ? (string) $root->prefix : 'atom';
            $namespaces[$prefix] = (string) $root->namespaceURI;
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('namespace::*') ?: [] as $node) {
            $prefix = $node->localName;
            $uri = $node->nodeValue;
            if ($prefix === null || $prefix === '' || $prefix === 'xml' || ! filled($uri)) {
                continue;
            }
            if ($prefix === 'xmlns') {
                $namespaces['atom'] = (string) $uri;

                continue;
            }
            $namespaces[$prefix] = (string) $uri;
        }

        // Walk elements for undeclared-but-used prefixes / default NS children.
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (! $element instanceof DOMElement || ! filled($element->namespaceURI)) {
                continue;
            }
            $uri = (string) $element->namespaceURI;
            if (filled($element->prefix)) {
                $namespaces[(string) $element->prefix] = $uri;
            } elseif ($uri === self::WELL_KNOWN_NAMESPACES['atom']) {
                $namespaces['atom'] = $uri;
            } elseif ($uri === self::WELL_KNOWN_NAMESPACES['g']) {
                $namespaces['g'] = $uri;
            }
        }

        return $namespaces;
    }

    /**
     * @param  array<string, string>  $namespaces
     */
    private function registerNamespaces(DOMXPath $xpath, array $namespaces): void
    {
        foreach ($namespaces as $prefix => $uri) {
            if (! is_string($prefix) || $prefix === '' || ! is_string($uri) || $uri === '') {
                continue;
            }
            $xpath->registerNamespace($prefix, $uri);
        }
    }

    /**
     * @param  array<string, array{path: string, count: int, score: int}>  $byPath
     */
    private function addCandidateScore(array &$byPath, string $path, int $count, int $score): void
    {
        if (! isset($byPath[$path]) || $score > $byPath[$path]['score'] || $count > $byPath[$path]['count']) {
            $byPath[$path] = [
                'path' => $path,
                'count' => $count,
                'score' => $score,
            ];
        }
    }

    /**
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     * @return array<int, array{path: string, count: int, label: string}>
     */
    private function mergeCandidate(array $candidates, string $path, int $count): array
    {
        foreach ($candidates as $index => $candidate) {
            if (($candidate['path'] ?? '') === $path) {
                $candidates[$index]['count'] = $count;
                $candidates[$index]['label'] = $this->candidateLabel($path, $count);

                return array_values($candidates);
            }
        }

        array_unshift($candidates, [
            'path' => $path,
            'count' => $count,
            'label' => $this->candidateLabel($path, $count),
        ]);

        return array_values($candidates);
    }

    private function scoreCandidate(string $path, int $count, DOMXPath $xpath, ?string $localName = null): int
    {
        $local = $localName ?? $this->localNameFromPath($path);
        $score = $count;

        if ($local !== null && in_array(mb_strtolower($local), self::PRODUCT_LIKE_NAMES, true)) {
            $score += 1000;
        }

        $nodes = @$xpath->query($path);
        $sample = ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;
        if ($sample instanceof DOMNode) {
            $fields = array_map(
                fn (string $field): string => mb_strtolower($this->fieldLocalName($field)),
                $this->collectChildFieldNames($sample),
            );
            foreach (['sku', 'id', 'ean', 'gtin', 'barcode', 'stock', 'qty', 'quantity', 'availability', 'price'] as $hint) {
                if (in_array($hint, $fields, true)) {
                    $score += 50;
                }
            }
        }

        // Prefer a simple //name path when it matches (non-namespaced feeds).
        // Namespace-safe local-name() paths still win when //name matches nothing.
        if (str_contains($path, 'local-name()')) {
            $score += 5;
        } else {
            $score += 25;
        }

        return $score;
    }

    private function localNameFromPath(string $path): ?string
    {
        if (preg_match('/local-name\(\)\s*=\s*"([^"]+)"/', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#^//([A-Za-z_][\w.-]*)$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function candidateLabel(string $path, int $count): string
    {
        $local = $this->localNameFromPath($path) ?? $path;

        return sprintf('%s — %d %s', $local, $count, $this->lithuanianProductsWord($count));
    }

    private function lithuanianProductsWord(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'produktas';
        }

        if ($mod10 >= 2 && $mod10 <= 9 && ($mod100 < 10 || $mod100 >= 20)) {
            return 'produktai';
        }

        return 'produktų';
    }

    /**
     * Friendly field names for UI / mappings, e.g. g:id, g:gtin, title.
     *
     * @return array<int, string>
     */
    private function collectChildFieldNames(DOMNode $item): array
    {
        $fields = [];

        foreach ($item->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $label = $this->friendlyFieldName($child);
            if ($label !== '' && ! in_array($label, $fields, true)) {
                $fields[] = $label;
            }
        }

        usort($fields, function (string $a, string $b): int {
            $aGoogle = str_starts_with($a, 'g:') ? 0 : 1;
            $bGoogle = str_starts_with($b, 'g:') ? 0 : 1;

            return [$aGoogle, $a] <=> [$bGoogle, $b];
        });

        return $fields;
    }

    private function friendlyFieldName(DOMElement $element): string
    {
        $local = $element->localName ?: $element->nodeName;
        if ($local === '') {
            return '';
        }

        $prefix = $element->prefix;
        if (filled($prefix)) {
            return $prefix.':'.$local;
        }

        if ($element->namespaceURI === self::WELL_KNOWN_NAMESPACES['g']) {
            return 'g:'.$local;
        }

        if ($element->namespaceURI === self::WELL_KNOWN_NAMESPACES['atom']) {
            // Prefer bare local name for Atom defaults (title, updated, …).
            return $local;
        }

        return $local;
    }

    private function fieldLocalName(string $field): string
    {
        return str_contains($field, ':')
            ? (string) explode(':', $field, 2)[1]
            : $field;
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $candidates
     */
    private function pickField(array $fields, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($fields as $field) {
                if (strcasecmp($field, $candidate) === 0) {
                    return $field;
                }
            }
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            $candidateLocal = $this->fieldLocalName($candidate);
            foreach ($fields as $field) {
                if (strcasecmp($this->fieldLocalName($field), $candidateLocal) === 0) {
                    $matches[] = $field;
                }
            }
            if ($matches !== []) {
                break;
            }
        }

        if ($matches === []) {
            return null;
        }

        foreach ($matches as $match) {
            if (str_starts_with($match, 'g:')) {
                return $match;
            }
        }

        return $matches[0];
    }

    private function childValue(DOMXPath $xpath, DOMNode $item, string $field): ?string
    {
        foreach ($this->fieldQueryCandidates($field) as $query) {
            $nodes = @$xpath->query($query, $item);
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
    private function fieldQueryCandidates(string $field): array
    {
        $local = $this->fieldLocalName($field);

        return array_values(array_unique([
            $field,
            '*[local-name()="'.$local.'"]',
            './/*[local-name()="'.$local.'"]',
        ]));
    }
}
