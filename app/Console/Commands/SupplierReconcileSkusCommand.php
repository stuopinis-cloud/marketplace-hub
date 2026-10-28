<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\Xml\SupplierSkuReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class SupplierReconcileSkusCommand extends Command
{
    protected $signature = 'supplier:reconcile-skus
                            {code : Supplier code}
                            {--apply : Apply renames (default is dry-run only)}';

    protected $description = 'Dry-run (or apply) reconciliation of supplier products incorrectly keyed by g:id after switching to g:SKU';

    public function handle(SupplierSkuReconciliationService $reconciliation): int
    {
        $code = (string) $this->argument('code');
        $supplier = Supplier::query()->where('code', $code)->first();

        if (! $supplier instanceof Supplier) {
            $this->components->error('Supplier not found: '.$code);

            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');

        try {
            $report = $reconciliation->reconcile($supplier, dryRun: $dryRun);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Dry-run ataskaita (niekas nekeista)' : 'Pritaikyta SKU sutvarkymas');
        $this->line('Tiekėjas: '.$report['supplier_code']);
        $this->line('XML įrašų: '.$report['feed_entries']);
        $this->line('Jau teisingi: '.$report['already_correct']);
        $this->line('Siūlomi pervadinimai: '.count($report['rename']));
        $this->line('Konfliktai: '.count($report['conflicts']));
        $this->line('Nesutapę esami: '.count($report['unmatched_existing']));
        if (! $dryRun) {
            $this->line('Pritaikyta: '.$report['applied']);
        }

        if ($report['rename'] !== []) {
            $this->newLine();
            $this->components->info('Pervadinimai:');
            foreach (array_slice($report['rename'], 0, 30) as $row) {
                $this->line(sprintf(
                    '  #%d  %s  →  %s  (EAN: %s)',
                    $row['supplier_product_id'],
                    $row['current_sku'],
                    $row['proposed_sku'],
                    $row['ean'] ?? '—',
                ));
            }
            if (count($report['rename']) > 30) {
                $this->line('  … ir dar '.(count($report['rename']) - 30));
            }
        }

        if ($report['conflicts'] !== []) {
            $this->newLine();
            $this->components->warn('Konfliktai (praleista):');
            foreach (array_slice($report['conflicts'], 0, 20) as $row) {
                $this->line(sprintf(
                    '  #%d  %s → %s  [%s]',
                    $row['supplier_product_id'],
                    $row['current_sku'],
                    $row['proposed_sku'] ?? '—',
                    $row['reason'],
                ));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Paleiskite su --apply, kad pritaikytumėte pervadinimus.');
        }

        return self::SUCCESS;
    }
}
