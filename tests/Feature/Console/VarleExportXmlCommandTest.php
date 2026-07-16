<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateVarleXmlJob;
use App\Models\SyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleExportXmlCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_command_runs_successfully(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $this->artisan('varle:export-xml')
            ->expectsOutputToContain('Varle XML export completed')
            ->expectsOutputToContain('Exported variants: 1')
            ->expectsOutputToContain('Skipped variants: 0')
            ->assertSuccessful();

        $this->assertSame(1, SyncJob::query()->count());
        Storage::disk('public')->assertExists('feeds/varle.xml');
    }

    public function test_command_can_dispatch_queue_job(): void
    {
        Bus::fake();

        $this->artisan('varle:export-xml --queue')
            ->expectsOutputToContain('queued')
            ->assertSuccessful();

        Bus::assertDispatched(GenerateVarleXmlJob::class);
    }
}
