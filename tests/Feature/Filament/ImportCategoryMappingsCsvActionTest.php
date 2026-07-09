<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CategoryMappings\Pages\ListCategoryMappings;
use App\Models\MarketplaceChannel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportCategoryMappingsCsvActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_list_page_shows_import_csv_action(): void
    {
        Livewire::test(ListCategoryMappings::class)
            ->assertActionExists('importCategoryMappingsCsv');
    }
}
