<?php

namespace Tests\Feature\Filament;

use App\Enums\SyncJobStatus;
use App\Filament\Widgets\ShopifyImportStatusWidget;
use App\Models\SyncJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopifyImportStatusWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_completed_summary(): void
    {
        $user = User::factory()->create();

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(10),
            'success_items' => 120,
            'context' => [
                'products_imported' => 120,
                'variants_imported' => 340,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ShopifyImportStatusWidget::class)
            ->assertSee('COMPLETED')
            ->assertSee('Last Shopify import completed successfully.');
    }

    public function test_widget_shows_stuck_warning(): void
    {
        $user = User::factory()->create();

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subMinutes(18),
            'total_items' => 743,
            'success_items' => 741,
            'context' => [
                'current_product_index' => 742,
                'current_product_handle' => 'stuck-product-handle',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ShopifyImportStatusWidget::class)
            ->assertSee('STUCK')
            ->assertSee('Shopify import appears stuck')
            ->assertSee('stuck-product-handle');
    }

    public function test_widget_shows_failed_error(): void
    {
        $user = User::factory()->create();

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Failed,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(5),
            'error_message' => 'Shopify API unavailable',
        ]);

        Livewire::actingAs($user)
            ->test(ShopifyImportStatusWidget::class)
            ->assertSee('FAILED')
            ->assertSee('Shopify import failed: Shopify API unavailable');
    }
}
