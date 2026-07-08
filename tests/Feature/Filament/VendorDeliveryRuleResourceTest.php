<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\VendorDeliveryRules\Pages\CreateVendorDeliveryRule;
use App\Filament\Resources\VendorDeliveryRules\Pages\ListVendorDeliveryRules;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use App\Models\VendorDeliveryRule;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorDeliveryRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_list_page_shows_create_action(): void
    {
        Livewire::test(ListVendorDeliveryRules::class)
            ->assertActionExists('create');
    }

    public function test_create_page_can_create_vendor_delivery_rule(): void
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'vendor-product',
            'title' => 'Helikon product',
            'vendor' => 'Helikon-Tex',
            'imported_at' => now(),
        ]);

        Livewire::test(CreateVendorDeliveryRule::class)
            ->fillForm([
                'vendor' => 'Helikon-Tex',
                'enabled' => true,
                'in_stock_delivery_text' => '2-4 d.d.',
                'backorder_delivery_text' => '10-20 d.d.',
                'allow_backorder_export' => true,
                'priority' => 100,
                'notes' => 'Test rule',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = VendorDeliveryRule::query()->firstOrFail();

        $this->assertSame('Helikon-Tex', $rule->vendor);
        $this->assertTrue($rule->enabled);
        $this->assertSame('2-4 d.d.', $rule->in_stock_delivery_text);
        $this->assertSame('10-20 d.d.', $rule->backorder_delivery_text);
        $this->assertTrue($rule->allow_backorder_export);
        $this->assertSame(100, $rule->priority);
        $this->assertSame('Test rule', $rule->notes);
    }
}
