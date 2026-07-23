<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Console\Command;
use Throwable;

class PiguExportProductsXmlCommand extends Command
{
    protected $signature = 'pigu:export-products-xml';

    protected $description = 'Export the Pigu / PHH Marketplace product XML feed';

    public function handle(PiguProductFeedExporter $exporter): int
    {
        try {
            $result = $exporter->export();
        } catch (Throwable $exception) {
            $this->components->error('Pigu product XML export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Pigu product XML export completed.');
        $this->line('Public path: '.$result['absolute_path']);
        $this->line('Public URL: '.$result['public_url']);
        $this->line('Exported products: '.$result['exported_products']);
        $this->line('Exported modifications: '.$result['exported_modifications']);
        $this->line('Skipped products: '.$result['skipped_products']);
        $this->line('Skipped modifications: '.$result['skipped_modifications']);

        return self::SUCCESS;
    }
}
