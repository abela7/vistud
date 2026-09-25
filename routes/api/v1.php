<?php

use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
| JSON API, version 1 (docs/architecture/contracts.md#http-api and
| docs/api/openapi.json).
|
| Prefix /api/v1, route names api.v1.*, middleware groups web + api.v1.
| Every route here must appear in both documents with its stability.
| Adding or changing one is a contract change.
*/

Route::middleware('auth')->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::get('/journal/entries/{id}', [JournalEntryController::class, 'show'])->name('journal.entries.show');
});
