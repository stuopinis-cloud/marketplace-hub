<?php

namespace Tests\Feature\Console;

use App\Models\MarketplaceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiguSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pigu_setup_creates_enabled_channel(): void
    {
        $this->artisan('pigu:setup')
            ->expectsOutputToContain('Pigu / PHH marketplace channel is configured')
            ->assertSuccessful();

        $channel = MarketplaceChannel::query()->where('type', 'pigu')->firstOrFail();
        $this->assertSame('Pigu / PHH', $channel->name);
        $this->assertTrue($channel->enabled);
    }
}
