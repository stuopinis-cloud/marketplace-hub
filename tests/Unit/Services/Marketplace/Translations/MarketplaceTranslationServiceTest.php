<?php

namespace Tests\Unit\Services\Marketplace\Translations;

use App\Enums\MarketplaceTranslationStatus;
use App\Enums\ProductStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Jobs\TranslateProductForMarketplaceJob;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Marketplace\Ebay\EbayFeedExporter;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use App\Services\Marketplace\Translations\NoopMarketplaceTranslator;
use App\Services\Marketplace\Translations\TranslationQueueService;
use App\Services\Marketplace\Varle\VarleExportResult;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleReadinessService;
use App\Services\Shopify\ShopifyImportResult;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class MarketplaceTranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_translation_record_is_created_when_en_translation_missing(): void
    {
        $product = $this->makeProduct('Unikalus produktas XYZ');

        $translation = app(MarketplaceTranslationService::class)->getOrCreateMissing(
            $product,
            MarketplaceTranslation::FIELD_TITLE,
            'en',
            'Unikalus produktas XYZ',
            'ebay',
        );

        $this->assertNotNull($translation);
        $this->assertSame(MarketplaceTranslationStatus::Missing, $translation->status);
        $this->assertSame('Unikalus produktas XYZ', $translation->source_text);
    }

    public function test_approved_translation_is_used(): void
    {
        $product = $this->makeProduct('Unikalus pavadinimas');
        $service = app(MarketplaceTranslationService::class);

        $record = $service->getOrCreateMissing($product, 'title', 'en', 'Unikalus pavadinimas', 'ebay');
        $record->update([
            'translated_text' => 'Unique Title',
            'status' => MarketplaceTranslationStatus::Approved,
            'provider' => 'manual',
        ]);

        $this->assertSame(
            'Unique Title',
            $service->applyTranslationOrFallback($product, 'title', 'en', 'Unikalus pavadinimas', 'ebay'),
        );
    }

    public function test_auto_translated_is_used_if_approved_missing(): void
    {
        $product = $this->makeProduct('Unikalus auto');
        $service = app(MarketplaceTranslationService::class);

        $record = $service->getOrCreateMissing($product, 'title', 'en', 'Unikalus auto', 'ebay');
        $record->update([
            'translated_text' => 'Unique Auto',
            'status' => MarketplaceTranslationStatus::AutoTranslated,
            'provider' => 'openai',
        ]);

        $this->assertSame(
            'Unique Auto',
            $service->applyTranslationOrFallback($product, 'title', 'en', 'Unikalus auto', 'ebay'),
        );
    }

    public function test_source_hash_change_creates_new_missing_translation(): void
    {
        $product = $this->makeProduct('Sena');
        $service = app(MarketplaceTranslationService::class);

        $old = $service->getOrCreateMissing($product, 'title', 'en', 'Sena', 'ebay');
        $old->update([
            'translated_text' => 'Old',
            'status' => MarketplaceTranslationStatus::Approved,
        ]);

        $new = $service->getOrCreateMissing($product, 'title', 'en', 'Nauja', 'ebay');

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(MarketplaceTranslationStatus::Missing, $new->status);
        $this->assertSame(
            'Nauja',
            $service->applyTranslationOrFallback($product, 'title', 'en', 'Nauja', 'ebay'),
        );
    }

    public function test_sku_barcode_brand_size_code_not_translated(): void
    {
        $service = app(MarketplaceTranslationService::class);
        $product = $this->makeProduct('Product');

        $this->assertNull($service->getOrCreateMissing($product, 'sku', 'en', 'SKU-123', 'ebay'));
        $this->assertSame('XL', $service->applyTranslationOrFallback($product, 'option_value', 'en', 'XL', 'ebay'));
        $this->assertSame('42', $service->applyTranslationOrFallback($product, 'option_value', 'en', '42', 'ebay'));
        $this->assertSame('NIJ', $service->applyTranslationOrFallback($product, 'attribute_value', 'en', 'NIJ', 'ebay'));
        $this->assertSame(0, MarketplaceTranslation::query()->count());
    }

    public function test_option_name_spalva_translates_to_color(): void
    {
        $variant = $this->makeVariant($this->makeProduct('Jacket'), 'Spalva', 'Juoda');
        $service = app(MarketplaceTranslationService::class);

        $this->assertSame(
            'Color',
            $service->applyTranslationOrFallback($variant, 'option_name:1', 'en', 'Spalva', 'ebay'),
        );
    }

    public function test_option_value_juoda_translates_to_black(): void
    {
        $variant = $this->makeVariant($this->makeProduct('Jacket'), 'Spalva', 'Juoda');
        $service = app(MarketplaceTranslationService::class);

        $this->assertSame(
            'Black',
            $service->applyTranslationOrFallback($variant, 'option_value:1', 'en', 'Juoda', 'ebay'),
        );
    }

    public function test_ebay_export_uses_translated_title_and_description(): void
    {
        $product = $this->makeProduct('Kuprinė taktinė', '<p>Aprašymas</p>');
        $this->makeVariant($product, 'Spalva', 'Juoda', 'BAG-1', '5901234123457');

        $service = app(MarketplaceTranslationService::class);
        $title = $service->getOrCreateMissing($product, 'title', 'en', 'Kuprinė taktinė', 'ebay');
        $title->update([
            'translated_text' => 'Tactical Backpack',
            'status' => MarketplaceTranslationStatus::Approved,
        ]);
        $description = $service->getOrCreateMissing($product, 'description', 'en', '<p>Aprašymas</p>', 'ebay');
        $description->update([
            'translated_text' => '<p>Description</p>',
            'status' => MarketplaceTranslationStatus::Approved,
        ]);

        $result = app(EbayFeedExporter::class)->export('en');
        $xml = \Illuminate\Support\Facades\Storage::disk('public')->get($result['feed_path']);

        $this->assertStringContainsString('Tactical Backpack', $xml);
        $this->assertStringContainsString('<![CDATA[<p>Description</p>]]>', $xml);
        $this->assertStringContainsString('<sku>BAG-1</sku>', $xml);
        $this->assertStringContainsString('<barcode>5901234123457</barcode>', $xml);
        $this->assertStringContainsString('<brand>Vendor</brand>', $xml);
    }

    public function test_ebay_export_keeps_sku_barcode_brand_unchanged(): void
    {
        $product = $this->makeProduct('Title');
        $this->makeVariant($product, 'Size', 'L', 'KEEP-SKU', '111');

        $xml = \Illuminate\Support\Facades\Storage::disk('public')->get(
            app(EbayFeedExporter::class)->export('en')['feed_path'],
        );

        $this->assertStringContainsString('<sku>KEEP-SKU</sku>', $xml);
        $this->assertStringContainsString('<barcode>111</barcode>', $xml);
        $this->assertStringContainsString('<brand>Vendor</brand>', $xml);
    }

    public function test_translation_job_failure_does_not_fail_daily_sync(): void
    {
        config(['marketplace.translations.auto_queue_missing_translations_for_ebay' => true]);

        $this->mock(ShopifyProductImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('import')->once()->andReturn(new ShopifyImportResult(1, 1, 1, 0));
        });

        $this->mock(TranslationQueueService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queueMissingForMarketplace')
                ->once()
                ->andThrow(new \RuntimeException('provider down'));
        });

        $this->mock(SupplierSyncManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncPublicationSuppliers')->once()->andReturn([]);
        });

        $this->mock(VarleReadinessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshAll')->once()->andReturn(0);
        });

        $this->mock(VarleFeedPublisher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')->once()->andReturn(new VarleExportResult(
                syncJobId: 1,
                exportedVariants: 1,
                skippedVariants: 0,
                feedPath: '/tmp/varle.xml',
                publicUrl: 'https://example.test/feeds/varle.xml',
            ));
        });

        $this->mock(SyncJobFailedCsvExporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveSyncJob')->once()->andReturn(null);
        });

        $result = app(DailyMarketplaceSync::class)->run();

        $this->assertTrue($result->successful);
        $this->assertNotEmpty($result->warnings);
        $this->assertArrayHasKey('ebay_translations_queued', $result->summary);
    }

    public function test_translation_actions_dispatch_queued_jobs_not_inline(): void
    {
        Queue::fake();
        $product = $this->makeProduct('Eilė');

        app(TranslationQueueService::class)->queueProduct($product->id, 'ebay', 'en');

        Queue::assertPushed(TranslateProductForMarketplaceJob::class);
    }

    public function test_manual_translation_override_is_preserved(): void
    {
        $product = $this->makeProduct('Rankinis');
        $service = app(MarketplaceTranslationService::class);
        $record = $service->getOrCreateMissing($product, 'title', 'en', 'Rankinis', 'ebay');
        $record->update([
            'translated_text' => 'Custom English',
            'status' => MarketplaceTranslationStatus::Approved,
            'provider' => 'manual',
        ]);

        TranslateProductFieldJob::dispatchSync($record->id);

        $record->refresh();
        $this->assertSame('Custom English', $record->translated_text);
        $this->assertSame(MarketplaceTranslationStatus::Approved, $record->status);
    }

    public function test_field_job_marks_auto_translated_with_noop_provider(): void
    {
        $this->app->bind(\App\Contracts\Marketplace\MarketplaceTranslatorInterface::class, NoopMarketplaceTranslator::class);

        $product = $this->makeProduct('Tekstas be glossary');
        $service = app(MarketplaceTranslationService::class);
        $record = $service->getOrCreateMissing($product, 'title', 'en', 'Tekstas be glossary', 'ebay');

        (new TranslateProductFieldJob($record->id))->handle($service, new NoopMarketplaceTranslator);

        $record->refresh();
        $this->assertSame(MarketplaceTranslationStatus::AutoTranslated, $record->status);
        $this->assertSame('Tekstas be glossary', $record->translated_text);
        $this->assertSame('manual', $record->provider);
    }

    private function makeProduct(string $title, string $description = ''): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        return Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-'.uniqid(),
            'title' => $title,
            'description_html' => $description,
            'vendor' => 'Vendor',
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);
    }

    private function makeVariant(
        Product $product,
        string $optionName,
        string $optionValue,
        string $sku = 'SKU-1',
        string $barcode = '123',
    ): ProductVariant {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-'.uniqid(),
            'sku' => $sku,
            'barcode' => $barcode,
            'title' => $optionValue,
            'price' => 10,
            'option1_name' => $optionName,
            'option1_value' => $optionValue,
            'option1' => $optionValue,
        ]);
    }
}
