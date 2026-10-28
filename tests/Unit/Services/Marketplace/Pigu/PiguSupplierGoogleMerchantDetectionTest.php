<?php

namespace Tests\Unit\Services\Marketplace\Pigu;

use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use Tests\TestCase;

class PiguSupplierGoogleMerchantDetectionTest extends TestCase
{
    public function test_detects_atom_entry_and_google_merchant_fields(): void
    {
        $detected = (new PiguSupplierXmlFieldDetector)->detect($this->sampleFeed());

        $this->assertSame('//*[local-name()="entry"]', $detected['item_path']);
        $this->assertGreaterThanOrEqual(2, $detected['item_count']);
        $this->assertContains('g:id', $detected['available_fields']);
        $this->assertContains('g:gtin', $detected['available_fields']);
        $this->assertContains('g:availability', $detected['available_fields']);
        $this->assertContains('g:price', $detected['available_fields']);
        $this->assertContains('g:title', $detected['available_fields']);
        $this->assertSame('g:id', $detected['sku_path']);
        $this->assertSame('g:gtin', $detected['barcode_path']);
        $this->assertSame('g:availability', $detected['stock_path']);
        $this->assertSame('g:availability', $detected['availability_path']);
        $this->assertSame('g:price', $detected['price_path']);
        $this->assertSame('g:title', $detected['title_path']);
        $this->assertSame('http://base.google.com/ns/1.0', $detected['namespaces']['g'] ?? null);
        $this->assertNotEmpty($detected['preview_rows']);
        $this->assertSame('MT-100', $detected['preview_rows'][0]['sku']);
        $this->assertSame('5901234567001', $detected['preview_rows'][0]['ean']);
        $this->assertSame('in stock', $detected['preview_rows'][0]['stock']);
        $this->assertSame('32.71 EUR', $detected['preview_rows'][0]['price']);
    }

    public function test_detect_for_item_path_refreshes_field_dropdowns(): void
    {
        $detector = new PiguSupplierXmlFieldDetector;
        $detected = $detector->detectForItemPath($this->sampleFeed(), '//*[local-name()="entry"]');

        $this->assertSame(2, $detected['item_count']);
        $this->assertNotEmpty($detected['available_fields']);
        $this->assertContains('g:id', $detected['available_fields']);
    }

    private function sampleFeed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">
  <title>M-Tac</title>
  <entry>
    <g:id>MT-100</g:id>
    <g:title>Knife</g:title>
    <g:gtin>5901234567001</g:gtin>
    <g:availability>in stock</g:availability>
    <g:price>32.71 EUR</g:price>
  </entry>
  <entry>
    <g:id>MT-200</g:id>
    <g:title>Bag</g:title>
    <g:gtin>5901234567002</g:gtin>
    <g:availability>out of stock</g:availability>
    <g:price>10.00 EUR</g:price>
  </entry>
</feed>
XML;
    }
}
