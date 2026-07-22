<?php

namespace Tests\Unit\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvParseException;
use App\Services\Suppliers\Csv\SupplierCsvParser;
use Tests\TestCase;

class SupplierCsvParserTest extends TestCase
{
    public function test_comma_delimited_csv_parses_stock(): void
    {
        $supplier = $this->makeSupplier([
            'csv_delimiter' => 'comma',
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
        ]);

        $parsed = $this->parser()->parse("sku,qty\nABC-1,12\n", $supplier);

        $this->assertCount(1, $parsed['entries']);
        $this->assertSame('ABC-1', $parsed['entries'][0]['sku']);
        $this->assertSame(12, $parsed['entries'][0]['stock_quantity']);
    }

    public function test_semicolon_delimiter_parses(): void
    {
        $supplier = $this->makeSupplier([
            'csv_delimiter' => 'semicolon',
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
        ]);

        $parsed = $this->parser()->parse("sku;qty\nABC-2;4\n", $supplier);

        $this->assertSame(4, $parsed['entries'][0]['stock_quantity']);
    }

    public function test_utf8_bom_is_stripped(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
        ]);

        $parsed = $this->parser()->parse("\xEF\xBB\xBFsku,qty\nBOM-1,2\n", $supplier);

        $this->assertSame('BOM-1', $parsed['entries'][0]['sku']);
    }

    public function test_quoted_fields_with_commas_parse(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_title_column' => 'title',
            'csv_stock_column' => 'qty',
        ]);

        $parsed = $this->parser()->parse("sku,title,qty\n\"SKU-1\",\"Title, long\",5\n", $supplier);

        $this->assertSame('SKU-1', $parsed['entries'][0]['sku']);
        $this->assertSame(5, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('Title, long', $parsed['entries'][0]['raw_payload']['title']);
    }

    public function test_truthy_availability_without_numeric_stock_becomes_fallback_candidate(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_availability_column' => 'availability',
        ]);

        $parsed = $this->parser()->parse("sku,availability\nFALLBACK-1,in stock\n", $supplier);

        $this->assertNull($parsed['entries'][0]['stock_quantity']);
        $this->assertSame('available', $parsed['entries'][0]['availability_status']);
    }

    public function test_explicit_zero_stays_unavailable(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
            'csv_availability_column' => 'availability',
        ]);

        $parsed = $this->parser()->parse("sku,qty,availability\nZERO-1,0,in stock\n", $supplier);

        $this->assertSame(0, $parsed['entries'][0]['stock_quantity']);
        $this->assertSame('unavailable', $parsed['entries'][0]['availability_status']);
    }

    public function test_missing_sku_rows_are_skipped(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
        ]);

        $parsed = $this->parser()->parse("sku,qty\n,3\nGOOD-1,2\n", $supplier);

        $this->assertCount(1, $parsed['skipped']);
        $this->assertSame('missing_sku', $parsed['skipped'][0]['issue_code']);
        $this->assertCount(1, $parsed['entries']);
    }

    public function test_sku_column_mapping_is_required_for_entry_parsing(): void
    {
        $supplier = $this->makeSupplier([]);

        $parsed = $this->parser()->parse("sku,qty\nA,1\n", $supplier);

        $this->assertSame(['sku', 'qty'], $parsed['headers']);
        $this->assertCount(1, $parsed['preview_rows']);
        $this->assertSame([], $parsed['entries']);
    }

    public function test_sync_importer_requires_sku_column(): void
    {
        $supplier = $this->makeSupplier([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV SKU column mapping is required.');

        app(\App\Services\Suppliers\Csv\SupplierCsvSupplierImporter::class)->sync($supplier);
    }

    public function test_auto_detects_semicolon_delimiter(): void
    {
        $supplier = $this->makeSupplier([
            'csv_delimiter' => 'auto',
            'csv_sku_column' => 'CODICE',
            'csv_stock_column' => 'QTA',
        ]);

        $parsed = $this->parser()->parse("CODICE;QTA;EAN\nSKU-9;3;5901234123457\n", $supplier);

        $this->assertSame(';', $parsed['detected_delimiter']);
        $this->assertSame('SKU-9', $parsed['entries'][0]['sku']);
        $this->assertSame(3, $parsed['entries'][0]['stock_quantity']);
    }

    public function test_windows_1252_encoding_converts_to_utf8(): void
    {
        $supplier = $this->makeSupplier([
            'csv_encoding' => 'Windows-1252',
            'csv_sku_column' => 'sku',
            'csv_title_column' => 'title',
            'csv_stock_column' => 'qty',
        ]);

        $latin1Title = 'Caf'.chr(0xE9);
        $csv = 'sku,title,qty'."\n".'ENC-1,'.$latin1Title.",2\n";

        $parsed = $this->parser()->parse($csv, $supplier);

        $this->assertSame('Café', $parsed['entries'][0]['raw_payload']['title']);
    }

    public function test_auto_encoding_converts_iso_8859_1_when_not_utf8(): void
    {
        $supplier = $this->makeSupplier([
            'csv_encoding' => 'auto',
            'csv_sku_column' => 'sku',
            'csv_title_column' => 'title',
            'csv_stock_column' => 'qty',
        ]);

        $latin1Title = 'na'.chr(0xEF).'ve';
        $csv = 'sku,title,qty'."\n".'ENC-2,'.$latin1Title.",1\n";

        $parsed = $this->parser()->parse($csv, $supplier);

        $this->assertSame('naïve', $parsed['entries'][0]['raw_payload']['title']);
    }

    public function test_null_enclosure_uses_double_quote(): void
    {
        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
            'csv_enclosure' => null,
        ]);

        $parsed = $this->parser()->parse("sku,qty\n\"ABC\",2\n", $supplier);

        $this->assertSame('ABC', $parsed['entries'][0]['sku']);
        $this->assertSame('"', $this->parser()->normalizeEnclosure(null));
    }

    public function test_empty_enclosure_uses_double_quote(): void
    {
        $this->assertSame('"', $this->parser()->normalizeEnclosure(''));
        $this->assertSame('"', $this->parser()->normalizeEnclosure('   '));

        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
            'csv_enclosure' => '',
        ]);

        $parsed = $this->parser()->parse("sku,qty\n\"XYZ\",5\n", $supplier);

        $this->assertSame('XYZ', $parsed['entries'][0]['sku']);
    }

    public function test_auto_enclosure_uses_double_quote(): void
    {
        $this->assertSame('"', $this->parser()->normalizeEnclosure('auto'));

        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
            'csv_enclosure' => 'auto',
        ]);

        $parsed = $this->parser()->parse("sku,qty\n\"AUTO-1\",1\n", $supplier);

        $this->assertSame('AUTO-1', $parsed['entries'][0]['sku']);
    }

    public function test_invalid_multi_character_enclosure_uses_double_quote(): void
    {
        $this->assertSame('"', $this->parser()->normalizeEnclosure('""'));
        $this->assertSame('"', $this->parser()->normalizeEnclosure('quote'));

        $supplier = $this->makeSupplier([
            'csv_sku_column' => 'sku',
            'csv_stock_column' => 'qty',
            'csv_enclosure' => '""',
        ]);

        $parsed = $this->parser()->parse("sku,qty\n\"SAFE\",8\n", $supplier);

        $this->assertSame('SAFE', $parsed['entries'][0]['sku']);
    }

    public function test_semicolon_csv_parses_with_default_enclosure(): void
    {
        $supplier = $this->makeSupplier([
            'csv_delimiter' => 'semicolon',
            'csv_sku_column' => 'sku',
            'csv_title_column' => 'title',
            'csv_stock_column' => 'qty',
            'csv_enclosure' => null,
            'csv_escape' => null,
        ]);

        $parsed = $this->parser()->parse("sku;title;qty\n\"SKU-1\";\"Title; long\";5\n", $supplier);

        $this->assertSame('SKU-1', $parsed['entries'][0]['sku']);
        $this->assertSame('Title; long', $parsed['entries'][0]['raw_payload']['title']);
        $this->assertSame(5, $parsed['entries'][0]['stock_quantity']);
    }

    public function test_prezioso_preview_works_with_auto_delimiter_and_default_enclosure(): void
    {
        $supplier = $this->makeSupplier([
            'csv_delimiter' => 'auto',
            'csv_encoding' => 'auto',
            'csv_enclosure' => 'auto',
            'csv_escape' => '',
            'csv_sku_column' => 'CODICE',
            'csv_stock_column' => 'QTA',
            'csv_barcode_column' => 'EAN',
            'matching_strategy' => 'sku_global',
        ]);

        $csv = "CODICE;EAN;QTA\n\"PREZ-1\";\"5901234123457\";\"12\"\n";
        $parsed = $this->parser()->parse($csv, $supplier, 20);

        $this->assertSame(';', $parsed['detected_delimiter']);
        $this->assertSame(['CODICE', 'EAN', 'QTA'], $parsed['headers']);
        $this->assertSame('PREZ-1', $parsed['preview_rows'][0]['CODICE']);
        $this->assertSame('PREZ-1', $parsed['entries'][0]['sku']);
        $this->assertSame(12, $parsed['entries'][0]['stock_quantity']);
    }

    private function parser(): SupplierCsvParser
    {
        return new SupplierCsvParser;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeSupplier(array $config): Supplier
    {
        return new Supplier([
            'name' => 'CSV Supplier',
            'code' => 'csv-test',
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'config' => $config,
        ]);
    }
}
