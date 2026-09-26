<?php

use App\Brain\Store\JournalReader;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Student screens (work package WP6). Each reads through the learner-scoped
| services: a student only ever sees their own stream, and an account
| without one (an admin who isn't a student) gets 403.
*/

Route::middleware('auth')->group(function () {
    // The student's journal, newest first.
    Route::get('/journal', function (Request $request, PrincipalFactory $principals, JournalReader $reader) {
        $entries = $reader->entries(Guard::learner($principals->fromRequest($request)));

        return view('journal.index', ['entries' => array_reverse($entries)]);
    })->name('journal.index');

    // One entry (App\Livewire\Journal\EntryShow). Another learner's ID answers 404, like a missing one.
    Route::view('/journal/{entry}', 'journal.show')->where('entry', '[A-Za-z0-9._:-]{1,64}')->name('journal.show');
});
