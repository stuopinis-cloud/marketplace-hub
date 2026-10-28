<?php

namespace Tests\Unit\Services\Marketplace\Pigu;

use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use Tests\TestCase;

class PiguSupplierXmlFieldDetectorTest extends TestCase
{
    public function test_detects_entry_elements_for_mtac_style_feeds(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed>
  <title>M-Tac</title>
  <entry>
    <sku>MT-1</sku>
    <ean>5901234567001</ean>
    <stock>4</stock>
    <price>10.00</price>
    <title>Knife</title>
  </entry>
  <entry>
    <sku>MT-2</sku>
    <ean>5901234567002</ean>
    <stock>1</stock>
    <price>12.50</price>
    <title>Bag</title>
  </entry>
</feed>
XML;

        $detected = (new PiguSupplierXmlFieldDetector)->detect($xml);

        $this->assertSame('//entry', $detected['item_path']);
        $this->assertSame(2, $detected['item_count']);
        $this->assertSame('sku', $detected['sku_path']);
        $this->assertSame('ean', $detected['barcode_path']);
        $this->assertTrue($this->candidatesContain($detected['item_path_candidates'], '//entry', 2));
    }

    public function test_detects_product_item_and_offer_roots(): void
    {
        $cases = [
            'product' => '<products><product><sku>A</sku><ean>1</ean><stock>1</stock><price>1</price></product><product><sku>B</sku><ean>2</ean><stock>2</stock><price>2</price></product></products>',
            'item' => '<catalog><item><sku>A</sku><ean>1</ean><qty>1</qty><price>1</price></item><item><sku>B</sku><ean>2</ean><qty>2</qty><price>2</price></item></catalog>',
            'offer' => '<offers><offer><sku>A</sku><ean>1</ean><stock>1</stock><price>1</price></offer><offer><sku>B</sku><ean>2</ean><stock>2</stock><price>2</price></offer></offers>',
        ];

        $detector = new PiguSupplierXmlFieldDetector;

        foreach ($cases as $localName => $body) {
            $xml = '<?xml version="1.0"?><root>'.$body.'</root>';
            $detected = $detector->detect($xml);

            $this->assertSame('//'.$localName, $detected['item_path'], "Failed for <{$localName}>");
            $this->assertSame(2, $detected['item_count'], "Count failed for <{$localName}>");
            $this->assertTrue(
                $this->candidatesContain($detected['item_path_candidates'], '//'.$localName, 2),
                "Candidates missing //{$localName}",
            );
        }
    }

    public function test_detects_nested_repeating_product_elements(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<catalog>
  <category name="Knives">
    <product><sku>N1</sku><ean>590111</ean><stock>3</stock><price>9.99</price></product>
    <product><sku>N2</sku><ean>590222</ean><stock>5</stock><price>14.00</price></product>
  </category>
  <category name="Bags">
    <product><sku>N3</sku><ean>590333</ean><stock>1</stock><price>20.00</price></product>
  </category>
</catalog>
XML;

        $detected = (new PiguSupplierXmlFieldDetector)->detect($xml);

        $this->assertSame('//product', $detected['item_path']);
        $this->assertSame(3, $detected['item_count']);
        $this->assertTrue($this->candidatesContain($detected['item_path_candidates'], '//product', 3));
    }

    public function test_detect_for_custom_item_path_supports_entry(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<root>
  <entry><sku>E1</sku><ean>1</ean><stock>2</stock><price>3</price></entry>
  <entry><sku>E2</sku><ean>2</ean><stock>4</stock><price>5</price></entry>
  <meta><note>ignore</note></meta>
</root>
XML;

        $detected = (new PiguSupplierXmlFieldDetector)->detectForItemPath($xml, '//entry');

        $this->assertSame('//entry', $detected['item_path']);
        $this->assertSame(2, $detected['item_count']);
        $this->assertCount(2, $detected['preview_rows']);
    }

    public function test_zero_match_custom_path_returns_zero_count(): void
    {
        $xml = '<?xml version="1.0"?><root><entry><sku>A</sku></entry></root>';

        $detected = (new PiguSupplierXmlFieldDetector)->detectForItemPath($xml, '//product');

        $this->assertSame('//product', $detected['item_path']);
        $this->assertSame(0, $detected['item_count']);
        $this->assertTrue($this->candidatesContain($detected['item_path_candidates'], '//entry', 1));
    }

    public function test_candidate_labels_include_counts(): void
    {
        $xml = '<?xml version="1.0"?><feed><entry><sku>A</sku><stock>1</stock><price>1</price></entry><entry><sku>B</sku><stock>2</stock><price>2</price></entry></feed>';

        $detected = (new PiguSupplierXmlFieldDetector)->detect($xml);
        $entry = collect($detected['item_path_candidates'])->firstWhere('path', '//entry');

        $this->assertNotNull($entry);
        $this->assertSame(2, $entry['count']);
        $this->assertStringContainsString('2', $entry['label']);
        $this->assertStringContainsString('entry', $entry['label']);
    }

    /**
     * @param  array<int, array{path: string, count: int, label: string}>  $candidates
     */
    private function candidatesContain(array $candidates, string $path, int $count): bool
    {
        foreach ($candidates as $candidate) {
            if (($candidate['path'] ?? null) === $path && (int) ($candidate['count'] ?? 0) === $count) {
                return true;
            }
        }

        return false;
    }
}
