<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\VarleReadiness;
use App\Jobs\GenerateVarleXmlJob;
use App\Jobs\ImportShopifyProductsJob;
use App\Jobs\RefreshVarleReadinessJob;
use App\Jobs\RunDailyMarketplaceSyncJob;
use App\Models\User;
use App\Services\Sync\MarketplaceJobLock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class VarleReadinessPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_refresh_readiness_action_dispatches_background_job(): void
    {
        Bus::fake();

        Livewire::test(VarleReadiness::class)
            ->callAction('refreshReadiness')
            ->assertNotified('Varle readiness refresh queued');

        Bus::assertDispatched(RefreshVarleReadinessJob::class);
    }

    public function test_shopify_import_action_dispatches_job_not_inline(): void
    {
        Bus::fake();

        Livewire::test(VarleReadiness::class)
            ->callAction('runShopifyImport')
            ->assertNotified('Shopify import queued');

        Bus::assertDispatched(ImportShopifyProductsJob::class);
    }

    public function test_varle_export_action_dispatches_job_not_inline(): void
    {
        Bus::fake();

        Livewire::test(VarleReadiness::class)
            ->callAction('runVarleExport')
            ->assertNotified('Varle export queued');

        Bus::assertDispatched(GenerateVarleXmlJob::class);
    }

    public function test_daily_sync_action_dispatches_job(): void
    {
        Bus::fake();

        Livewire::test(VarleReadiness::class)
            ->callAction('runDailySync')
            ->assertNotified('Daily marketplace sync queued');

        Bus::assertDispatched(RunDailyMarketplaceSyncJob::class);
    }

    public function test_duplicate_shopify_import_is_blocked_by_lock(): void
    {
        Bus::fake();

        $lock = MarketplaceJobLock::make(MarketplaceJobLock::SHOPIFY_IMPORT);
        $this->assertTrue($lock->get());

        try {
            Livewire::test(VarleReadiness::class)
                ->callAction('runShopifyImport')
                ->assertNotified('Job already running');

            Bus::assertNotDispatched(ImportShopifyProductsJob::class);
        } finally {
            $lock->release();
        }
    }

    public function test_duplicate_varle_export_is_blocked_by_lock(): void
    {
        Bus::fake();

        $lock = MarketplaceJobLock::make(MarketplaceJobLock::VARLE_EXPORT);
        $this->assertTrue($lock->get());

        try {
            Livewire::test(VarleReadiness::class)
                ->callAction('runVarleExport')
                ->assertNotified('Job already running');

            Bus::assertNotDispatched(GenerateVarleXmlJob::class);
        } finally {
            $lock->release();
        }
    }
}
