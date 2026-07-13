<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SyncJob;
use App\Services\Suppliers\Mtac\MtacSupplierSyncOptions;
use App\Services\Suppliers\Mtac\MtacSupplierSyncResult;
use App\Services\Suppliers\SupplierSyncManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SupplierSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_setup_mtac_command_creates_supplier(): void
    {
        $this->artisan('supplier:setup-mtac')->assertSuccessful();

        $supplier = Supplier::query()->where('code', 'mtac')->first();
        $this->assertNotNull($supplier);
        $this->assertSame('M-Tac', $supplier->name);
        $this->assertSame('https://m-tac.pl/xml?id=42', $supplier->endpoint_url);
        $this->assertTrue($supplier->sync_enabled);
    }

    public function test_supplier_sync_command_runs_manager(): void
    {
        $this->mock(SupplierSyncManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->with('mtac', \Mockery::type(MtacSupplierSyncOptions::class))
                ->andReturn(new MtacSupplierSyncResult(1, 2, 1, 1, 0, 0, 1, 1, 0, 0));
        });

        $this->artisan('supplier:sync mtac --dry-run')->assertSuccessful();
    }
}
