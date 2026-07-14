<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\VendorDeliveryRule;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\Varle\VarleDeliveryResolver;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleReadinessQueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_run_context_avoids_repeated_mapping_and_rule_queries(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'Shoes',
            'target_category_path' => 'Footwear > Shoes',
            'enabled' => true,
            'priority' => 10,
        ]);

        VendorDeliveryRule::query()->create([
            'vendor' => 'Vendor Name',
            'enabled' => true,
            'in_stock_delivery_text' => '2-4 d.d.',
            'allow_backorder_export' => true,
        ]);

        $variant = VarleCatalogFixtures::createExportableVariant();
        $product = $variant->product->fresh([
            'variants.inventoryLevels',
            'variants.supplierProducts.supplier',
            'images',
            'sourceCategories',
        ]);

        $service = app(VarleReadinessService::class);
        $context = $service->createRunContext();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = $service->analyze($product, context: $context);
        $second = $service->analyze($product, context: $context);

        $queries = collect(DB::getQueryLog())->pluck('query');
        $mappingQueries = $queries->filter(
            fn (string $sql): bool => str_contains(strtolower($sql), 'category_mappings'),
        )->count();
        $ruleQueries = $queries->filter(
            fn (string $sql): bool => str_contains(strtolower($sql), 'vendor_delivery_rules'),
        )->count();
        $channelQueries = $queries->filter(
            fn (string $sql): bool => str_contains(strtolower($sql), 'marketplace_channels'),
        )->count();

        $this->assertNotSame([], $first);
        $this->assertSame($first['is_ready_for_varle'], $second['is_ready_for_varle']);
        $this->assertSame(0, $mappingQueries);
        $this->assertSame(0, $ruleQueries);
        $this->assertSame(0, $channelQueries);
    }

    public function test_delivery_resolver_preload_matches_legacy_lookup(): void
    {
        VendorDeliveryRule::query()->create([
            'vendor' => 'Vendor Name',
            'enabled' => true,
            'in_stock_delivery_text' => '2-4 d.d.',
            'backorder_delivery_text' => '10-20 d.d.',
            'allow_backorder_export' => true,
        ]);

        $variant = VarleCatalogFixtures::createExportableVariant();
        $product = $variant->product;
        $channelConfig = [
            'delivery_in_stock_text' => '1-2 d.d.',
            'delivery_backorder_text' => '5-10 d.d.',
            'allow_backorder_export' => true,
        ];

        $resolver = app(VarleDeliveryResolver::class);
        $preload = $resolver->preloadRules();

        $this->assertSame(
            $resolver->resolveForProduct($product, $channelConfig),
            $resolver->resolveForProductPreloaded($product, $channelConfig, $preload),
        );
    }
}
