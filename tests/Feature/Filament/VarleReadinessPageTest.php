<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\VarleReadiness;
use App\Jobs\RefreshVarleReadinessJob;
use App\Models\User;
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
            ->assertNotified('Varle readiness refresh started in background.');

        Bus::assertDispatched(RefreshVarleReadinessJob::class);
    }
}
