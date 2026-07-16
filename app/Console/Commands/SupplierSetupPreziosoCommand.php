<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use Illuminate\Console\Command;

class SupplierSetupPreziosoCommand extends Command
{
    protected $signature = 'supplier:setup-prezioso';

    protected $description = 'Create or update the Prezioso CSV/NTLM supplier configuration';

    public function handle(SupplierSyncManager $manager): int
    {
        $supplier = $manager->setupPrezioso();

        $this->components->info('Prezioso supplier is configured.');
        $this->line('ID: '.$supplier->id);
        $this->line('Code: '.$supplier->code);
        $this->line('Endpoint: '.$supplier->endpoint_url);
        $this->line('Auth: '.$supplier->auth_type);
        $this->line('Sync enabled: '.($supplier->sync_enabled ? 'yes' : 'no'));
        $this->newLine();
        $this->line('Set PREZIOSO_NTLM_USERNAME / PREZIOSO_NTLM_PASSWORD (or encrypted Filament credentials).');
        $this->line('Preview the CSV in Filament, then map SKU / barcode / stock columns before syncing.');

        return self::SUCCESS;
    }
}
