<?php

namespace Tests\Unit\Services\Suppliers\Mtac;

use App\Services\Suppliers\Mtac\MtacXmlParser;
use Tests\TestCase;

class MtacXmlParserTest extends TestCase
{
    private MtacXmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MtacXmlParser;
    }

    public function test_parses_sku_stock_and_availability_with_namespace(): void
    {
        $entries = $this->parser->parse($this->sampleFeed([
            ['sku' => 'ABC-123', 'stock' => '12', 'availability' => 'in_stock'],
        ]));

        $this->assertCount(1, $entries);
        $this->assertSame('ABC-123', $entries[0]['sku']);
        $this->assertSame(12, $entries[0]['stock_quantity']);
        $this->assertSame('available', $entries[0]['availability_status']);
    }

    public function test_stock_zero_is_unavailable(): void
    {
        $entries = $this->parser->parse($this->sampleFeed([
            ['sku' => 'ZERO-1', 'stock' => '0', 'availability' => 'in_stock'],
        ]));

        $this->assertSame(0, $entries[0]['stock_quantity']);
        $this->assertSame('unavailable', $entries[0]['availability_status']);
    }

    public function test_missing_stock_uses_availability_true_values(): void
    {
        foreach (['yes', 'true', 'in_stock', 'available', 'yra', 'sandelyje'] as $value) {
            $entries = $this->parser->parse($this->sampleFeed([
                ['sku' => 'SKU-'.$value, 'stock' => null, 'availability' => $value],
            ]));

            $this->assertSame(1, $entries[0]['stock_quantity'], $value);
            $this->assertSame('available', $entries[0]['availability_status'], $value);
        }
    }

    public function test_missing_stock_uses_availability_false_values(): void
    {
        foreach (['no', 'false', 'out_of_stock', 'unavailable', 'nėra', 'nera'] as $value) {
            $entries = $this->parser->parse($this->sampleFeed([
                ['sku' => 'SKU-'.$value, 'stock' => null, 'availability' => $value],
            ]));

            $this->assertSame(0, $entries[0]['stock_quantity'], $value);
            $this->assertSame('unavailable', $entries[0]['availability_status'], $value);
        }
    }

    public function test_unknown_availability_is_unavailable(): void
    {
        $entries = $this->parser->parse($this->sampleFeed([
            ['sku' => 'UNKNOWN', 'stock' => null, 'availability' => 'maybe'],
        ]));

        $this->assertSame(0, $entries[0]['stock_quantity']);
        $this->assertSame('unavailable', $entries[0]['availability_status']);
    }

    /**
     * @param  array<int, array{sku: string, stock: ?string, availability: ?string}>  $rows
     */
    private function sampleFeed(array $rows): string
    {
        $entries = '';

        foreach ($rows as $row) {
            $entries .= '<entry>';
            $entries .= '<g:SKU>'.htmlspecialchars($row['sku']).'</g:SKU>';

            if ($row['stock'] !== null) {
                $entries .= '<g:stock>'.htmlspecialchars($row['stock']).'</g:stock>';
            }

            if ($row['availability'] !== null) {
                $entries .= '<g:availability>'.htmlspecialchars($row['availability']).'</g:availability>';
            }

            $entries .= '</entry>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">'
            .$entries
            .'</feed>';
    }
}
