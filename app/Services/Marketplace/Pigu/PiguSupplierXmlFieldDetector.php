<?php

namespace App\Services\Marketplace\Pigu;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Autodetects likely XML field paths for Pigu offers-only supplier setup.
 */
class PiguSupplierXmlFieldDetector
{
    /**
     * Product-like element local names (case-insensitive), scored highest when repeating.
     *
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
        'sku',
    ];

    /**
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
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
        $candidates = $this->discoverItemPathCandidates($xpath, $document->documentElement);
        $itemPath = $candidates[0]['path'] ?? null;

        if ($itemPath === null) {
            return $this->emptyResult();
        }

        return $this->buildResultForPath($xpath, $itemPath, $candidates);
    }

    /**
     * Re-run field/preview detection for a user-selected (possibly custom) item path.
     *
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
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
    public function detectForItemPath(string $xml, string $itemPath, array $candidates = []): array
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
        $itemPath = trim($itemPath);

        if ($itemPath === '') {
            return $this->emptyResult();
        }

        if ($candidates === []) {
            $candidates = $this->discoverItemPathCandidates($xpath, $document->documentElement);
        }

        $count = $this->countPath($xpath, $itemPath);
        $candidates = $this->mergeCandidate($candidates, $itemPath, $count);

        return $this->buildResultForPath($xpath, $itemPath, $candidates);
    }

    /**
     * Count nodes matching an XPath. Returns 0 for invalid XPath.
     */
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
     * @return array{
     *     item_path: null,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
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
            'item_path_candidates' => [],
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

    /**
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     * @return array{
     *     item_path: ?string,
     *     item_path_candidates: array<int, array{path: string, count: int, label: string}>,
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
    private function buildResultForPath(DOMXPath $xpath, string $itemPath, array $candidates): array
    {
        $items = @$xpath->query($itemPath);
        if ($items === false || $items->length === 0) {
            return [
                'item_path' => $itemPath,
                'item_path_candidates' => $candidates,
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

        $first = $items->item(0);
        $fields = $first instanceof DOMNode
            ? $this->collectChildFieldNames($first)
            : [];

        $skuPath = $this->pickField($fields, [
            'sku', 'product_sku', 'productsku', 'code', 'product_code', 'id', 'supplier_sku', 'mpn',
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
            'item_path_candidates' => $candidates,
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
     * @return array<int, array{path: string, count: int, label: string}>
     */
    private function discoverItemPathCandidates(DOMXPath $xpath, DOMElement $root): array
    {
        /** @var array<string, array{path: string, count: int, score: int}> $byPath */
        $byPath = [];

        $knownPaths = [];
        foreach (self::PRODUCT_LIKE_NAMES as $name) {
            $knownPaths[] = '//'.$name;
            $knownPaths[] = '//*[local-name()="'.$name.'"]';
        }

        foreach ($knownPaths as $path) {
            $count = $this->countPath($xpath, $path);
            if ($count <= 0) {
                continue;
            }
            $this->addCandidateScore($byPath, $path, $count, $this->scoreCandidate($path, $count, $xpath));
        }

        // Structural: repeating element local-names under any parent (including nested).
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

                // Prefer simple //name; use local-name() when prefixed/namespaced.
                $sample = $meta['sample'];
                $path = ($sample instanceof DOMElement && $sample->prefix)
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

        // Direct repeating children of root (e.g. <feed><entry/>...).
        $rootChildCounts = [];
        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $name = $child->localName ?: $child->nodeName;
            $rootChildCounts[$name] = ($rootChildCounts[$name] ?? 0) + 1;
        }

        foreach ($rootChildCounts as $name => $count) {
            if ($count < 1) {
                continue;
            }
            $path = '//'.$name;
            $this->addCandidateScore($byPath, $path, $count, $this->scoreCandidate($path, $count, $xpath, $name) + 5);
        }

        uasort($byPath, function (array $a, array $b): int {
            return [$b['score'], $b['count']] <=> [$a['score'], $a['count']];
        });

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

        // Prefer elements that look like products (have identifiable child fields).
        $nodes = @$xpath->query($path);
        $sample = ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;
        if ($sample instanceof DOMNode) {
            $fields = array_map('mb_strtolower', $this->collectChildFieldNames($sample));
            foreach (['sku', 'ean', 'barcode', 'gtin', 'stock', 'qty', 'quantity', 'price'] as $hint) {
                if (in_array($hint, $fields, true)) {
                    $score += 50;
                }
            }
        }

        // Prefer shorter, simpler paths.
        if (str_starts_with($path, '//') && ! str_contains($path, 'local-name')) {
            $score += 10;
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
        $productsWord = $this->lithuanianProductsWord($count);

        return sprintf('%s — %d %s', $local, $count, $productsWord);
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
