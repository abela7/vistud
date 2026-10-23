<?php

use App\Http\Controllers\IconSpriteController;
use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

/*
| Web routes. Screens are Livewire adapters over the application services
| (ADR 0003 §8). To avoid conflicts between work packages, each area has its
| own file under routes/web/, owned by one package
| (docs/handoff/m1-work-packages.md). Fortify registers its own routes.
*/

// Guests start at the login screen. Signed-in accounts get a placeholder
// until the workspace screens arrive (WP6, then M2).
// An old link to the New course dialog (/?new=1) leads to the New course page.
Route::get('/', fn () => ! auth()->check() ? redirect()->route('login') : (request()->boolean('new') ? redirect()->route('workspaces.create') : view('home')))->name('home');

// Every icon, once, for the browser to keep (App\Appearance\Icons).
Route::get('/icons.svg', IconSpriteController::class)->name('icons');

// How ViStud shows up outside itself (App\Appearance\Share): what crawlers may read, the pages they may list, and
// what a phone needs to keep it on its home screen.
Route::get('/robots.txt', [ShareController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [ShareController::class, 'sitemap'])->name('sitemap');
Route::get('/manifest.webmanifest', [ShareController::class, 'manifest'])->name('manifest');

require __DIR__.'/web/auth.php';
require __DIR__.'/web/identity.php';
require __DIR__.'/web/student.php';
require __DIR__.'/web/admin.php';
