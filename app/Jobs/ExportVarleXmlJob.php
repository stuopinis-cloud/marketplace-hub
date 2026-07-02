<?php

namespace App\Jobs;

use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportVarleXmlJob implements ShouldQueue
{
    use Queueable;

    public function handle(VarleXmlExporter $exporter): void
    {
        $exporter->export();
    }
}
