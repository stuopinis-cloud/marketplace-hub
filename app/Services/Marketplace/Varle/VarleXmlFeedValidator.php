<?php

namespace App\Services\Marketplace\Varle;

use DOMDocument;

class VarleXmlFeedValidator
{
    public function validate(string $absolutePath): VarleXmlFeedValidationResult
    {
        $errors = [];

        if (! is_file($absolutePath)) {
            return VarleXmlFeedValidationResult::invalid(['Varle feed file was not found.']);
        }

        if (filesize($absolutePath) === 0) {
            return VarleXmlFeedValidationResult::invalid(['Varle feed file is empty.']);
        }

        $content = file_get_contents($absolutePath);

        if (! is_string($content) || trim($content) === '') {
            return VarleXmlFeedValidationResult::invalid(['Varle feed file is unreadable.']);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $loaded = $document->load($absolutePath, LIBXML_NONET | LIBXML_COMPACT);

        if (! $loaded) {
            $errors[] = 'Varle feed is not well-formed XML.';
        } else {
            $root = $document->documentElement;

            if ($root === null || $root->nodeName !== 'products') {
                $errors[] = 'Varle feed root element must be <products>.';
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (preg_match_all('/<quantity>(-?\d+)<\/quantity>/', $content, $matches) > 0) {
            foreach ($matches[1] as $quantity) {
                if ((int) $quantity <= 0) {
                    $errors[] = 'Varle feed contains a non-positive <quantity> value.';
                    break;
                }
            }
        }

        if (str_contains($content, '<quantity>0</quantity>')) {
            $errors[] = 'Varle feed must not contain <quantity>0</quantity>.';
        }

        return $errors === []
            ? VarleXmlFeedValidationResult::valid()
            : VarleXmlFeedValidationResult::invalid(array_values(array_unique($errors)));
    }
}
