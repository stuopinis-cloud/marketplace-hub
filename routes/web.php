<?php

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
