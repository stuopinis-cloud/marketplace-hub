<?php

namespace Tests\Unit\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Services\Suppliers\Xml\SupplierXmlParser;
use Tests\TestCase;

class SupplierXmlParserTest extends TestCase
{
    public function test_parses_configured_item_and_stock_paths(): void
    {
        $supplier = new Supplier([
            'code' => 'acme',
            'config' => [
                'xml_item_path' => '//item',
                'xml_sku_path' => 'sku',
                'xml_stock_path' => 'qty',
                'xml_title_path' => 'name',
            ],
        ]);

        $parsed = (new SupplierXmlParser)->parse(
            '<?xml version="1.0"?><catalog><item><sku>A-1</sku><qty>4</qty><name>Knife</name></item></catalog>',
            $supplier,
        );

        $this->assertCount(1, $parsed['entries']);
        $this->assertSame('A-1', $parsed['entries'][0]['sku']);
        $this->assertSame(4, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('Knife', $parsed['entries'][0]['raw_payload']['title']);
    }

    public function test_supports_registered_namespaces(): void
    {
        $supplier = new Supplier([
            'code' => 'ns',
            'config' => [
                'xml_item_path' => '//g:entry',
                'xml_sku_path' => 'g:id',
                'xml_stock_path' => 'g:qty',
                'xml_namespaces' => [
                    'g' => 'http://base.google.com/ns/1.0',
                ],
            ],
        ]);

        $xml = <<<'XML'
<?xml version="1.0"?>
<root xmlns:g="http://base.google.com/ns/1.0">
  <g:entry><g:id>NS-1</g:id><g:qty>2</g:qty></g:entry>
</root>
XML;

        $parsed = (new SupplierXmlParser)->parse($xml, $supplier);

        $this->assertSame('NS-1', $parsed['entries'][0]['sku']);
        $this->assertSame(2, $parsed['entries'][0]['stock_quantity']);
    }

    public function test_parses_custom_entry_item_path(): void
    {
        $supplier = new Supplier([
            'code' => 'mtac-xml',
            'config' => [
                'xml_item_path' => '//entry',
                'xml_sku_path' => 'sku',
                'xml_barcode_path' => 'ean',
                'xml_stock_path' => 'stock',
                'xml_price_path' => 'price',
            ],
        ]);

        $xml = <<<'XML'
<?xml version="1.0"?>
<feed>
  <entry><sku>MT-1</sku><ean>5901</ean><stock>7</stock><price>11.10</price></entry>
  <entry><sku>MT-2</sku><ean>5902</ean><stock>0</stock><price>9.00</price></entry>
</feed>
XML;

        $parsed = (new SupplierXmlParser)->parse($xml, $supplier);

        $this->assertCount(2, $parsed['entries']);
        $this->assertSame('MT-1', $parsed['entries'][0]['sku']);
        $this->assertSame(7, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame(11.10, $parsed['entries'][0]['price']);
    }

    public function test_zero_match_item_path_throws_lithuanian_error(): void
    {
        $supplier = new Supplier([
            'code' => 'bad-path',
            'config' => [
                'xml_item_path' => '//product',
                'xml_sku_path' => 'sku',
                'xml_stock_path' => 'stock',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nerado nė vieno produkto');

        (new SupplierXmlParser)->parse(
            '<?xml version="1.0"?><feed><entry><sku>A</sku><stock>1</stock></entry></feed>',
            $supplier,
        );
    }

    public function test_invalid_xpath_throws_lithuanian_error(): void
    {
        $supplier = new Supplier([
            'code' => 'bad-xpath',
            'config' => [
                'xml_item_path' => '//*[',
                'xml_sku_path' => 'sku',
                'xml_stock_path' => 'stock',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neteisingas');

        (new SupplierXmlParser)->parse(
            '<?xml version="1.0"?><feed><entry><sku>A</sku><stock>1</stock></entry></feed>',
            $supplier,
        );
    }
}
