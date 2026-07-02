<?php

namespace App\Console\Commands;

use App\Jobs\ExportVarleXmlJob;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Console\Command;
use Throwable;

class VarleExportXmlCommand extends Command
{
    protected $signature = 'varle:export-xml {--queue : Dispatch the export to the queue} {--debug : Print color grouping details for the first 10 products}';

    protected $description = 'Export product variants to a Varle.lt XML feed';

    public function handle(VarleXmlExporter $exporter): int
    {
        if ($this->option('queue')) {
            ExportVarleXmlJob::dispatch();
            $this->components->info('Varle XML export dispatched to the queue.');

            return self::SUCCESS;
        }

        try {
            $result = $exporter->export(debug: (bool) $this->option('debug'));
        } catch (Throwable $exception) {
            $this->components->error('Varle XML export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Varle XML export completed.');
        $this->line('Sync job ID: '.$result->syncJobId);
        $this->line('Exported variants: '.$result->exportedVariants);
        $this->line('Skipped variants: '.$result->skippedVariants);
        $this->line('Feed path: '.$result->feedPath);
        $this->line('Public URL: '.$result->publicUrl);

        if ($result->debugLines !== []) {
            $this->newLine();
            $this->components->info('Color grouping debug (first 10 products with variants):');
            foreach ($result->debugLines as $line) {
                $this->line($line);
            }
        }

        return $result->skippedVariants > 0 ? self::FAILURE : self::SUCCESS;
    }
}
