<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Console\Command;

class SupplierSetupHelikCommand extends Command
{
    protected $signature = 'supplier:setup-helik';

    protected $description = 'Create or update the Helikon / Direct-Action supplier configuration';

    public function handle(SupplierSyncManager $manager): int
    {
        $supplier = $manager->setupHelik();

        $this->components->info('Helikon / Direct-Action supplier is configured.');
        $this->line('ID: '.$supplier->id);
        $this->line('Code: '.$supplier->code);
        $this->line('Endpoint: '.$supplier->endpoint_url);
        $this->line('Sync enabled: '.($supplier->sync_enabled ? 'yes' : 'no'));
        $this->line('Configure ENTIREM_API_TOKEN or encrypted supplier credentials before syncing.');

        return self::SUCCESS;
    }
}
