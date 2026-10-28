<?php

namespace Tests\Unit\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use App\Services\Suppliers\Xml\SupplierSkuReconciliationService;
use App\Services\Suppliers\Xml\SupplierXmlFeedClient;
use App\Services\Suppliers\Xml\SupplierXmlFieldValueResolver;
use App\Services\Suppliers\Xml\SupplierXmlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupplierXmlSkuFieldResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_uses_g_sku_not_g_id(): void
    {
        $supplier = $this->merchantSupplier('g:SKU');
        $parsed = (new SupplierXmlParser)->parse($this->sampleFeed(), $supplier);

        $this->assertCount(2, $parsed['entries']);
        $this->assertSame('3-121-18', $parsed['entries'][0]['sku']);
        $this->assertSame('18', $parsed['entries'][0]['raw_payload']['merchant_id']);
        $this->assertSame('g:SKU', $parsed['entries'][0]['raw_payload']['sku_field']);
        $this->assertNotSame('18', $parsed['entries'][0]['sku']);
        $this->assertSame('3-121-19', $parsed['entries'][1]['sku']);
    }

    public function test_case_sensitive_sku_field_does_not_match_lowercase_sku(): void
    {
        $supplier = $this->merchantSupplier('g:sku');
        $parsed = (new SupplierXmlParser)->parse($this->sampleFeed(), $supplier);

        // XML has g:SKU (uppercase). Configured g:sku must not silently resolve to it or g:id.
        $this->assertSame([], $parsed['entries']);
        $this->assertNotEmpty($parsed['skipped']);
        $this->assertSame('missing_sku', $parsed['skipped'][0]['issue_code']);
    }

    public function test_missing_configured_sku_field_does_not_fall_back_to_g_id(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">
  <entry>
    <g:id>18</g:id>
    <g:gtin>5901234567001</g:gtin>
    <g:stock>3</g:stock>
    <g:price>10.00 EUR</g:price>
  </entry>
</feed>
XML;

        $supplier = $this->merchantSupplier('g:SKU');
        $parsed = (new SupplierXmlParser)->parse($xml, $supplier);

        $this->assertSame([], $parsed['entries']);
        $this->assertSame('missing_sku', $parsed['skipped'][0]['issue_code']);
    }

    public function test_detector_prefers_g_sku_over_g_id_and_preview_shows_both(): void
    {
        $detected = (new PiguSupplierXmlFieldDetector)->detect($this->sampleFeed());

        $this->assertSame('g:SKU', $detected['sku_path']);
        $this->assertNotSame('g:id', $detected['sku_path']);
        $this->assertContains('g:SKU', $detected['available_fields']);
        $this->assertContains('g:id', $detected['available_fields']);
        $this->assertSame('18', $detected['preview_rows'][0]['merchant_id']);
        $this->assertSame('3-121-18', $detected['preview_rows'][0]['sku']);
    }

    public function test_preview_with_mappings_uses_selected_sku_field(): void
    {
        $detector = new PiguSupplierXmlFieldDetector;
        $preview = $detector->previewWithMappings(
            $this->sampleFeed(),
            '//*[local-name()="entry"]',
            [
                'sku_path' => 'g:SKU',
                'barcode_path' => 'g:gtin',
                'stock_path' => 'g:stock',
                'price_path' => 'g:price',
                'title_path' => 'g:title',
            ],
        );

        $this->assertSame('3-121-18', $preview['preview_rows'][0]['sku']);
        $this->assertSame('18', $preview['preview_rows'][0]['merchant_id']);
        $this->assertSame('5901234567001', $preview['preview_rows'][0]['ean']);
    }

    public function test_field_resolver_is_case_sensitive_for_local_name(): void
    {
        $document = new \DOMDocument;
        $document->loadXML($this->sampleFeed());
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('g', 'http://base.google.com/ns/1.0');
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $entry = $xpath->query('//*[local-name()="entry"]')->item(0);

        $resolver = new SupplierXmlFieldValueResolver;
        $this->assertSame('3-121-18', $resolver->valueAt($xpath, 'g:SKU', $entry));
        $this->assertNull($resolver->valueAt($xpath, 'g:sku', $entry));
        $this->assertSame('18', $resolver->valueAt($xpath, 'g:id', $entry));
    }

    public function test_reconciliation_dry_run_renames_g_id_keyed_products(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac-sku-fix',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://example.test/mtac.xml',
            'config' => $this->merchantSupplier('g:SKU')->config,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => '18',
            'barcode' => '5901234567001',
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'raw_payload' => ['merchant_id' => '18'],
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => '3-121-19',
            'barcode' => '5901234567002',
            'stock_quantity' => 1,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
        ]);

        $client = Mockery::mock(SupplierXmlFeedClient::class);
        $client->shouldReceive('fetch')->andReturn($this->sampleFeed());

        $service = new SupplierSkuReconciliationService($client, new SupplierXmlParser);
        $report = $service->reconcile($supplier, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertCount(1, $report['rename']);
        $this->assertSame('18', $report['rename'][0]['current_sku']);
        $this->assertSame('3-121-18', $report['rename'][0]['proposed_sku']);
        $this->assertSame(1, $report['already_correct']);
        $this->assertSame(0, $report['applied']);
        $this->assertSame('18', SupplierProduct::query()->whereKey($report['rename'][0]['supplier_product_id'])->value('supplier_sku'));
    }

    public function test_reconciliation_apply_updates_sku_without_duplicates(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac-sku-apply',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://example.test/mtac.xml',
            'config' => $this->merchantSupplier('g:SKU')->config,
        ]);

        $wrong = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => '18',
            'barcode' => '5901234567001',
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'product_variant_id' => null,
            'enabled' => true,
            'raw_payload' => ['merchant_id' => '18'],
        ]);

        $client = Mockery::mock(SupplierXmlFeedClient::class);
        $client->shouldReceive('fetch')->andReturn($this->sampleFeed());

        $service = new SupplierSkuReconciliationService($client, new SupplierXmlParser);
        $report = $service->reconcile($supplier, dryRun: false);

        $this->assertSame(1, $report['applied']);
        $wrong->refresh();
        $this->assertSame('3-121-18', $wrong->supplier_sku);
        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $supplier->id)->where('supplier_sku', '3-121-18')->count());
        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $supplier->id)->where('supplier_sku', '18')->count());
    }

    public function test_reimport_with_correct_sku_is_idempotent(): void
    {
        $supplier = $this->merchantSupplier('g:SKU');
        $parser = new SupplierXmlParser;
        $first = $parser->parse($this->sampleFeed(), $supplier);
        $second = $parser->parse($this->sampleFeed(), $supplier);

        $this->assertSame(
            array_column($first['entries'], 'sku'),
            array_column($second['entries'], 'sku'),
        );
        $this->assertSame(['3-121-18', '3-121-19'], array_column($first['entries'], 'sku'));
    }

    private function merchantSupplier(string $skuPath): Supplier
    {
        return new Supplier([
            'code' => 'mtac-g',
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'config' => [
                'xml_item_path' => '//*[local-name()="entry"]',
                'xml_sku_path' => $skuPath,
                'xml_barcode_path' => 'g:gtin',
                'xml_stock_path' => 'g:stock',
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
    <g:id>18</g:id>
    <g:SKU>3-121-18</g:SKU>
    <g:title>Knife</g:title>
    <g:gtin>5901234567001</g:gtin>
    <g:stock>4</g:stock>
    <g:availability>in stock</g:availability>
    <g:price>32.71 EUR</g:price>
  </entry>
  <entry>
    <g:id>19</g:id>
    <g:SKU>3-121-19</g:SKU>
    <g:title>Bag</g:title>
    <g:gtin>5901234567002</g:gtin>
    <g:stock>1</g:stock>
    <g:availability>in stock</g:availability>
    <g:price>10.00 EUR</g:price>
  </entry>
</feed>
XML;
    }
}
