<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Enums\VarleExportStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\SyncJobItem;
use App\Services\Marketplace\Varle\VarleExportGatekeeper;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleExportApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'marketplace.exports.varle.store_url' => 'https://ebunkeris.lt',
        ]);
    }

    public function test_migration_sets_existing_products_to_auto(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'varle_export_status'));

        Product::query()->create([
            'source_id' => Source::query()->create([
                'type' => 'shopify',
                'name' => 'Shopify',
                'enabled' => true,
                'config' => [],
            ])->id,
            'external_id' => 'legacy-1',
            'title' => 'Legacy product',
            'status' => 'active',
            'varle_export_status' => VarleExportStatus::Auto,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('products', [
            'external_id' => 'legacy-1',
            'varle_export_status' => 'auto',
        ]);
    }

    public function test_pending_review_product_is_skipped_from_xml(): void
    {
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::PendingReview,
        ]);

        $result = app(VarleXmlExporter::class)->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertStringNotContainsString('<product>', Storage::disk('public')->get('feeds/varle.xml'));

        $item = SyncJobItem::query()->firstOrFail();
        $this->assertSame('Product pending Varle review', $item->message);
        $this->assertSame('pending_review', data_get($item->payload, 'varle_export_status'));
    }

    public function test_exclude_product_is_skipped_from_xml(): void
    {
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Exclude,
        ]);

        $result = app(VarleXmlExporter::class)->export();

        $this->assertSame(0, $result->exportedVariants);

        $item = SyncJobItem::query()->firstOrFail();
        $this->assertSame('Product excluded from Varle export', $item->message);
    }

    public function test_auto_product_is_skipped_when_category_mapping_export_is_disabled(): void
    {
        $channel = $this->configureVarleChannel();
        $product = VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Auto,
            'product_type' => 'Gloves',
        ])->product;

        SourceCategory::query()->create([
            'source_id' => $product->source_id,
            'type' => 'product_type',
            'name' => 'Gloves',
        ])->products()->sync([$product->id]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Gloves',
            'target_category_path' => 'Mapped Gloves',
            'priority' => 100,
            'enabled' => true,
            'export_enabled' => false,
        ]);

        $result = app(VarleXmlExporter::class)->export();

        $this->assertSame(0, $result->exportedVariants);

        $item = SyncJobItem::query()->firstOrFail();
        $this->assertSame('Category mapping disabled for Varle export', $item->message);
        $this->assertFalse((bool) data_get($item->payload, 'category_mapping_export_enabled'));
    }

    public function test_include_product_exports_even_when_category_mapping_export_is_disabled(): void
    {
        $channel = $this->configureVarleChannel();
        $product = VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Include,
            'product_type' => 'Gloves',
        ])->product;

        SourceCategory::query()->create([
            'source_id' => $product->source_id,
            'type' => 'product_type',
            'name' => 'Gloves',
        ])->products()->sync([$product->id]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Gloves',
            'target_category_path' => 'Mapped Gloves',
            'priority' => 100,
            'enabled' => true,
            'export_enabled' => false,
        ]);

        $result = app(VarleXmlExporter::class)->export();

        $this->assertSame(1, $result->exportedVariants);
        $this->assertStringContainsString('<product>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_preview_counts_match_exporter_gatekeeping(): void
    {
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::PendingReview,
        ]);
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Exclude,
        ]);
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Auto,
        ]);

        $preview = app(VarleXmlExporter::class)->preview();

        $this->assertSame(1, $preview->exportableProducts);
        $this->assertSame(1, $preview->pendingReviewProducts);
        $this->assertSame(1, $preview->excludedProducts);
    }

    public function test_gatekeeper_messages_match_expected_rules(): void
    {
        $channel = $this->configureVarleChannel();
        $product = VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::PendingReview,
        ])->product;

        $gate = app(VarleExportGatekeeper::class)->assess($product, $channel);

        $this->assertFalse($gate->allowed);
        $this->assertSame('Product pending Varle review', $gate->skipMessage);
    }

    public function test_preview_command_outputs_summary(): void
    {
        VarleCatalogFixtures::createExportableVariant([
            'varle_export_status' => VarleExportStatus::Auto,
        ]);

        $this->artisan('varle:preview-export')
            ->expectsOutputToContain('Varle export preview')
            ->expectsOutputToContain('Products that would be exported: 1')
            ->assertSuccessful();
    }

    private function configureVarleChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                ],
            ],
        );
    }
}
