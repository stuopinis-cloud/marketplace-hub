<?php

namespace Tests\Unit\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Xml\SupplierXmlParser;
use Tests\TestCase;

class SupplierXmlGoogleMerchantParserTest extends TestCase
{
    public function test_parses_google_merchant_atom_feed_with_g_fields(): void
    {
        $supplier = $this->merchantSupplier();
        $parsed = (new SupplierXmlParser)->parse($this->sampleFeed(), $supplier);

        $this->assertCount(3, $parsed['entries']);
        $this->assertSame('3-121-MT-100', $parsed['entries'][0]['sku']);
        $this->assertSame('MT-100', $parsed['entries'][0]['raw_payload']['merchant_id']);
        $this->assertSame('5901234567001', $parsed['entries'][0]['barcode']);
        $this->assertSame('Knife', $parsed['entries'][0]['title']);
        $this->assertSame(32.71, $parsed['entries'][0]['price']);
        $this->assertSame('EUR', $parsed['entries'][0]['currency']);
        $this->assertNull($parsed['entries'][0]['stock_quantity']);
        $this->assertSame(SupplierProduct::AVAILABILITY_AVAILABLE, $parsed['entries'][0]['availability_status']);
    }

    public function test_out_of_stock_string_becomes_zero(): void
    {
        $supplier = $this->merchantSupplier();
        $parsed = (new SupplierXmlParser)->parse($this->sampleFeed(), $supplier);

        $this->assertSame('3-121-MT-200', $parsed['entries'][1]['sku']);
        $this->assertSame(0, $parsed['entries'][1]['stock_quantity']);
        $this->assertSame(SupplierProduct::AVAILABILITY_UNAVAILABLE, $parsed['entries'][1]['availability_status']);
    }

    public function test_preorder_is_not_treated_as_available_quantity(): void
    {
        $supplier = $this->merchantSupplier();
        $parsed = (new SupplierXmlParser)->parse($this->sampleFeed(), $supplier);

        $this->assertSame('3-121-MT-300', $parsed['entries'][2]['sku']);
        $this->assertSame(0, $parsed['entries'][2]['stock_quantity']);
        $this->assertSame(SupplierProduct::AVAILABILITY_UNAVAILABLE, $parsed['entries'][2]['availability_status']);
    }

    public function test_parse_price_with_currency_variants(): void
    {
        $parser = new SupplierXmlParser;

        $this->assertSame([32.71, 'EUR'], $parser->parsePriceWithCurrency('32.71 EUR'));
        $this->assertSame([10.5, 'USD'], $parser->parsePriceWithCurrency('USD 10.50'));
        $this->assertSame([19.99, 'EUR'], $parser->parsePriceWithCurrency('19,99 EUR'));
        $this->assertSame([null, null], $parser->parsePriceWithCurrency(''));
    }

    public function test_non_namespaced_xml_still_parses(): void
    {
        $supplier = new Supplier([
            'code' => 'plain',
            'config' => [
                'xml_item_path' => '//item',
                'xml_sku_path' => 'sku',
                'xml_barcode_path' => 'ean',
                'xml_stock_path' => 'qty',
                'xml_price_path' => 'price',
                'xml_title_path' => 'name',
            ],
        ]);

        $parsed = (new SupplierXmlParser)->parse(
            '<?xml version="1.0"?><catalog><item><sku>A-1</sku><ean>123</ean><qty>4</qty><price>9.99</price><name>Knife</name></item></catalog>',
            $supplier,
        );

        $this->assertSame('A-1', $parsed['entries'][0]['sku']);
        $this->assertSame(4, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame(9.99, $parsed['entries'][0]['price']);
    }

    private function merchantSupplier(): Supplier
    {
        return new Supplier([
            'code' => 'mtac-g',
            'availability_fallback_quantity' => 5,
            'config' => [
                'xml_item_path' => '//*[local-name()="entry"]',
                'xml_sku_path' => 'g:SKU',
                'xml_barcode_path' => 'g:gtin',
                'xml_stock_path' => 'g:availability',
                'xml_availability_path' => 'g:availability',
                'xml_price_path' => 'g:price',
                'xml_title_path' => 'g:title',
                'xml_namespaces' => [
                    'atom' => 'http://www.w3.org/2005/Atom',
                    'g' => 'http://base.google.com/ns/1.0',
                ],
            ],
        ]);
    }

    private function sampleFeed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">
  <entry>
    <g:id>MT-100</g:id>
    <g:SKU>3-121-MT-100</g:SKU>
    <g:title>Knife</g:title>
    <g:gtin>5901234567001</g:gtin>
    <g:availability>in stock</g:availability>
    <g:price>32.71 EUR</g:price>
  </entry>
  <entry>
    <g:id>MT-200</g:id>
    <g:SKU>3-121-MT-200</g:SKU>
    <g:title>Bag</g:title>
    <g:gtin>5901234567002</g:gtin>
    <g:availability>out of stock</g:availability>
    <g:price>10.00 EUR</g:price>
  </entry>
  <entry>
    <g:id>MT-300</g:id>
    <g:SKU>3-121-MT-300</g:SKU>
    <g:title>Preorder item</g:title>
    <g:gtin>5901234567003</g:gtin>
    <g:availability>preorder</g:availability>
    <g:price>15.00 EUR</g:price>
  </entry>
</feed>
XML;
    }
}
