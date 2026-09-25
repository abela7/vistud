<?php

use Illuminate\Support\Facades\Route;

/*
| Web routes. Screens are Livewire adapters over the application services
| (ADR 0003 §8). To avoid conflicts between work packages, each area has its
| own file under routes/web/, owned by one package
| (docs/handoff/m1-work-packages.md). Fortify registers its own routes.
*/

Route::get('/', function () {
    return view('welcome');
});

require __DIR__.'/web/identity.php';
require __DIR__.'/web/admin.php';
