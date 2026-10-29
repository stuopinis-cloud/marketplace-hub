<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PiguSupplierOfferPreviewController extends Controller
{
    public function downloadXml(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        return $this->download($supplier, $preview, PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION, 'xml');
    }

    public function downloadSkippedCsv(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        return $this->download($supplier, $preview, PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION, 'csv');
    }

    public function downloadCombinedXml(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        return $this->download($supplier, $preview, PiguExportOptions::PREVIEW_SCOPE_COMBINED, 'xml');
    }

    public function downloadCombinedSkippedCsv(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        return $this->download($supplier, $preview, PiguExportOptions::PREVIEW_SCOPE_COMBINED, 'csv');
    }

    private function download(
        Supplier $supplier,
        PiguSupplierOfferPreviewService $preview,
        string $scope,
        string $type,
    ): BinaryFileResponse {
        abort_unless($supplier->connector_type === Supplier::CONNECTOR_XML_URL, 404);

        try {
            $files = $preview->readyDownloadPaths($supplier, $scope);
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        if ($type === 'csv') {
            return response()->download(
                $files['skipped_csv_absolute_path'],
                'pigu-offers-'.$scope.'-skipped-'.$supplier->code.'.csv',
                ['Content-Type' => 'text/csv'],
            );
        }

        return response()->download(
            $files['absolute_path'],
            'pigu-offers-'.$scope.'-preview-'.$supplier->code.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }
}
