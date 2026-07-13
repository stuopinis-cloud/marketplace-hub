<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use Illuminate\Console\Command;

class SupplierSetupMtacCommand extends Command
{
    protected $signature = 'supplier:setup-mtac';

    protected $description = 'Create or update the M-Tac supplier configuration';

    public function handle(SupplierSyncManager $manager): int
    {
        $supplier = $manager->setupMtac();

        $this->components->info('M-Tac supplier is configured.');
        $this->line('ID: '.$supplier->id);
        $this->line('Code: '.$supplier->code);
        $this->line('Endpoint: '.$supplier->endpoint_url);
        $this->line('Sync enabled: '.($supplier->sync_enabled ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
