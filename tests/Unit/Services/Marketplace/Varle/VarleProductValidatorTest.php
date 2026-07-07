<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\MarketplaceChannel;
use App\Services\Marketplace\Varle\VarleProductValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleProductValidatorTest extends TestCase
{
    use RefreshDatabase;

    private VarleProductValidator $validator;

    private MarketplaceChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = $this->app->make(VarleProductValidator::class);
        $this->channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [
                'default_category' => 'Kita',
                'export_zero_stock' => true,
            ],
        ]);
    }

    public function test_validator_catches_missing_sku(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(variantOverrides: ['sku' => null]);

        $result = $this->validator->validateVariant($variant, $this->channelConfig());

        $this->assertFalse($result->isValid);
        $this->assertContains('SKU is required.', $result->errors);
    }

    public function test_validator_catches_missing_barcode(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(variantOverrides: ['barcode' => null]);

        $result = $this->validator->validateVariant($variant, $this->channelConfig());

        $this->assertFalse($result->isValid);
        $this->assertContains('Barcode is required.', $result->errors);
    }

    public function test_validator_catches_missing_image(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(variantOverrides: [
            'image_url' => null,
        ]);
        $variant->product->images()->delete();
        $variant->product->load(['images', 'variants']);

        $result = $this->validator->validateProduct(
            $variant->product,
            $this->channel,
            $this->channelConfig(),
        );

        $this->assertFalse($result->isValid);
        $this->assertContains('No variant-specific images found', $result->errors);
    }

    public function test_validator_accepts_valid_variant(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant();

        $result = $this->validator->validateVariant($variant, $this->channelConfig());

        $this->assertTrue($result->isValid);
        $this->assertSame([], $result->errors);
    }

    public function test_validator_catches_unresolved_category(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant([
            'category' => null,
            'product_type' => null,
        ]);

        $channel = MarketplaceChannel::query()->create([
            'name' => 'Empty Varle',
            'type' => 'varle-empty',
            'enabled' => true,
            'config' => [],
        ]);

        $result = $this->validator->validateProduct(
            $variant->product,
            $channel,
            [],
        );

        $this->assertFalse($result->isValid);
        $this->assertContains('Category could not be resolved.', $result->errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelConfig(): array
    {
        return [
            'default_category' => 'Kita',
            'export_zero_stock' => true,
            'price_multiplier' => 1,
        ];
    }
}
