<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\Helik\HelikFeedClient;
use Illuminate\Console\Command;

class SupplierDebugRequestCommand extends Command
{
    protected $signature = 'supplier:debug-request {supplier : Supplier code, e.g. helik}';

    protected $description = 'Print safe Helikon / Entirem request diagnostics without exposing credentials';

    public function handle(HelikFeedClient $client): int
    {
        $supplierCode = (string) $this->argument('supplier');

        if ($supplierCode !== Supplier::CODE_HELIK) {
            $this->components->error('supplier:debug-request currently supports only the helik supplier.');

            return self::FAILURE;
        }

        $supplier = Supplier::query()->where('code', $supplierCode)->first();

        if ($supplier === null) {
            $this->components->error(sprintf('Supplier "%s" was not found.', $supplierCode));

            return self::FAILURE;
        }

        $description = $client->describeRequest($supplier);

        $this->line('endpoint: '.$description['endpoint']);
        $this->line('method: '.$description['method']);
        $this->line('auth_type: '.$description['auth_type']);
        $this->line('has_token: '.($description['has_token'] ? 'true' : 'false'));
        $this->newLine();
        $this->line('headers:');

        foreach ($description['headers'] as $name => $value) {
            $this->line(sprintf('  %s: %s', $name, $value));
        }

        $this->newLine();
        $this->line('body_json: '.$description['body_json']);
        $this->newLine();
        $this->line('body_key_types:');

        foreach ($description['body_key_types'] as $key => $type) {
            $this->line(sprintf('  %s: %s', $key, $type));
        }

        return self::SUCCESS;
    }
}
