<?php

use App\Http\Controllers\CategoryMappingImportFailedController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicFeedController;
use App\Http\Controllers\VarleFailedExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthController::class);

Route::get('/feeds/varle.xml', [PublicFeedController::class, 'varle']);

Route::middleware('auth')->get(
    '/exports/varle-failed/{syncJobId}.csv',
    [VarleFailedExportController::class, 'download'],
)->whereNumber('syncJobId')->name('exports.varle-failed');

Route::middleware('auth')->get(
    '/exports/category-mapping-import-failed/{filename}',
    [CategoryMappingImportFailedController::class, 'download'],
)->where('filename', '[A-Za-z0-9_\-]+\.csv')->name('exports.category-mapping-import-failed');
