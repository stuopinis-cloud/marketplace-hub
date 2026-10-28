<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PiguSupplierOfferPreviewController extends Controller
{
    public function downloadXml(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        abort_unless($supplier->connector_type === Supplier::CONNECTOR_XML_URL, 404);

        $files = $preview->ensurePreviewFiles($supplier);

        return response()->download(
            $files['absolute_path'],
            'pigu-offers-preview-'.$supplier->code.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function downloadSkippedCsv(Supplier $supplier, PiguSupplierOfferPreviewService $preview): BinaryFileResponse
    {
        abort_unless($supplier->connector_type === Supplier::CONNECTOR_XML_URL, 404);

        $files = $preview->ensurePreviewFiles($supplier);

        return response()->download(
            $files['skipped_csv_absolute_path'],
            'pigu-offers-skipped-'.$supplier->code.'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }
}
