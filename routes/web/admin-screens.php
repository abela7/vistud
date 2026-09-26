<?php

use Illuminate\Support\Facades\Route;

/*
| Admin screens (work package WP6). Loaded inside the admin route group
| (routes/web/admin.php): every route here needs the admin role, confirmed
| 2FA and a fresh password on entry, and is named admin.*.
*/

Route::view('/', 'admin.overview')->name('overview');
