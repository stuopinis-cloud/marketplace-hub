<?php

namespace App\Http\Controllers;

use App\Models\SyncJob;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VarleFailedExportController extends Controller
{
    public function download(int $syncJobId, SyncJobFailedCsvExporter $exporter): BinaryFileResponse
    {
        $syncJob = SyncJob::query()->findOrFail($syncJobId);

        abort_unless($syncJob->type === 'export', 404);

        return $exporter->downloadResponse($syncJob);
    }
}
