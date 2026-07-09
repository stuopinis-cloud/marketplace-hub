<?php

namespace App\Http\Controllers;

use App\Services\Marketplace\CategoryMappingCsvImporter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CategoryMappingImportFailedController extends Controller
{
    public function download(string $filename): BinaryFileResponse
    {
        $relativePath = CategoryMappingCsvImporter::FAILED_CSV_DIRECTORY.'/'.$filename;

        abort_unless(Storage::disk('public')->exists($relativePath), 404);

        return response()->download(
            Storage::disk('public')->path($relativePath),
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
