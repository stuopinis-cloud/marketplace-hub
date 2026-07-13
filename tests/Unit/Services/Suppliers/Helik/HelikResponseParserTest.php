<?php

namespace Tests\Unit\Services\Suppliers\Helik;

use App\Services\Suppliers\Helik\HelikResponseParser;
use Tests\TestCase;

class HelikResponseParserTest extends TestCase
{
    private HelikResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new HelikResponseParser;
    }

    public function test_parses_value_array_rows(): void
    {
        $parsed = $this->parser->parse(json_encode([
            'Value' => [
                ['SKU' => ' ABC123 ', 'Quantity' => 12],
            ],
        ]));

        $this->assertCount(1, $parsed['entries']);
        $this->assertSame('ABC123', $parsed['entries'][0]['sku']);
        $this->assertSame(12, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('available', $parsed['entries'][0]['availability_status']);
    }

    public function test_quantity_zero_is_unavailable(): void
    {
        $parsed = $this->parser->parse(json_encode([
            'Value' => [['SKU' => 'ZERO', 'Quantity' => 0]],
        ]));

        $this->assertSame(0, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('unavailable', $parsed['entries'][0]['availability_status']);
    }

    public function test_negative_quantity_is_normalized_to_zero(): void
    {
        $parsed = $this->parser->parse(json_encode([
            'Value' => [['SKU' => 'NEG', 'Quantity' => -5]],
        ]));

        $this->assertSame(0, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('unavailable', $parsed['entries'][0]['availability_status']);
    }

    public function test_missing_sku_is_skipped(): void
    {
        $parsed = $this->parser->parse(json_encode([
            'Value' => [['Quantity' => 2]],
        ]));

        $this->assertSame([], $parsed['entries']);
        $this->assertSame('missing_sku', $parsed['skipped'][0]['issue_code']);
    }

    public function test_missing_quantity_is_unavailable_with_issue_code(): void
    {
        $parsed = $this->parser->parse(json_encode([
            'Value' => [['SKU' => 'NO-QTY']],
        ]));

        $this->assertSame(0, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('missing_quantity', $parsed['entries'][0]['parse_issue_code']);
    }

    public function test_malformed_json_fails_safely(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $this->parser->parse('{not-json');
    }
}
