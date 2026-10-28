<?php

namespace App\Services\Suppliers\Xml;

use DOMNode;
use DOMXPath;

/**
 * Resolves a configured XML field path to a node value.
 *
 * Matching is namespace-aware and case-sensitive:
 * - "g:SKU" matches only g:SKU (not g:sku, not g:id)
 * - Missing fields return null — never silently fall back to another field
 */
class SupplierXmlFieldValueResolver
{
    public function valueAt(DOMXPath $xpath, string $fieldPath, DOMNode $context): ?string
    {
        $fieldPath = trim($fieldPath);
        if ($fieldPath === '') {
            return null;
        }

        foreach ($this->queryCandidates($fieldPath) as $candidate) {
            $previous = libxml_use_internal_errors(true);
            $nodes = @$xpath->query($candidate, $context);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            // Require exact local-name + prefix match — never return a sibling like g:id for g:SKU.
            $node = $this->pickExactNode($nodes, $fieldPath);
            if ($node === null) {
                continue;
            }

            $value = trim((string) $node->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function queryCandidates(string $fieldPath): array
    {
        if (str_contains($fieldPath, 'local-name()') || str_contains($fieldPath, '/')) {
            return [$fieldPath];
        }

        $local = $this->localName($fieldPath);
        $prefix = $this->prefix($fieldPath);

        $candidates = [];

        // 1) Exact QName when a prefix is configured (requires registered namespace).
        if ($prefix !== null) {
            $candidates[] = $prefix.':'.$local;
        } else {
            $candidates[] = $local;
        }

        // 2) Case-sensitive local-name match among direct children, then descendants.
        //    Exact node filtering in pickExactNode keeps prefix/case fidelity.
        $candidates[] = '*[local-name()="'.$local.'"]';
        $candidates[] = './/*[local-name()="'.$local.'"]';

        return array_values(array_unique($candidates));
    }

    /**
     * @param  \DOMNodeList  $nodes
     */
    private function pickExactNode(\DOMNodeList $nodes, string $fieldPath): ?DOMNode
    {
        $local = $this->localName($fieldPath);
        $prefix = $this->prefix($fieldPath);

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $nodeLocal = $node->localName ?: $node->nodeName;
            if ($nodeLocal !== $local) {
                // Case-sensitive: "SKU" !== "sku"
                continue;
            }

            if ($prefix === null) {
                return $node;
            }

            $nodePrefix = $node->prefix ?: null;
            if ($nodePrefix === $prefix) {
                return $node;
            }

            // Google Merchant elements may appear without a stored prefix but with the g namespace URI.
            if ($prefix === 'g' && $node->namespaceURI === 'http://base.google.com/ns/1.0') {
                return $node;
            }
        }

        // If the QName/local-name query returned nodes but none matched case/prefix exactly,
        // do not return a wrong sibling (e.g. never return g:id for g:SKU).
        return null;
    }

    public function localName(string $fieldPath): string
    {
        if (str_contains($fieldPath, ':')) {
            return (string) explode(':', $fieldPath, 2)[1];
        }

        return $fieldPath;
    }

    public function prefix(string $fieldPath): ?string
    {
        if (! str_contains($fieldPath, ':')) {
            return null;
        }

        $prefix = (string) explode(':', $fieldPath, 2)[0];

        return $prefix !== '' ? $prefix : null;
    }
}
