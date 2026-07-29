<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FeedFiles\Pages\ListFeedFiles;
use App\Enums\FeedFileStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class FeedFilesPublicFeedsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_public_feeds_ui_includes_pigu_product_and_offer_feeds(): void
    {
        Artisan::call('pigu:setup');

        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-products.xml',
            'path' => 'feeds/pigu-products.xml',
            'public_url' => 'https://hub.gudle.lt/feeds/pigu-products.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-offers.xml',
            'path' => 'feeds/pigu-offers.xml',
            'public_url' => 'https://hub.gudle.lt/feeds/pigu-offers.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);

        Livewire::test(ListFeedFiles::class)
            ->assertSee('pigu-products.xml')
            ->assertSee('pigu-offers.xml');
    }
}

