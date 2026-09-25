<?php

use Illuminate\Support\Facades\Route;

/*
| Web routes. Screens are Livewire adapters over the application services
| (ADR 0003 §8). To avoid conflicts between work packages, each area gets
| its own file under routes/web/ and is required from here; the package
| that owns an area owns its file (docs/handoff/m1-work-packages.md).
*/

Route::get('/', function () {
    return view('welcome');
});
