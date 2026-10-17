<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

// Public ingest contract (docs/protocol/v1.md). Stateless: the "api" middleware group has no session or CSRF.
Route::prefix('v1')->group(function () {
    Route::post('ingest', IngestController::class)->name('api.v1.ingest');
});
