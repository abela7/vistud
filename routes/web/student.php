<?php

use App\Brain\Store\JournalReader;
use App\Http\Controllers\NotePageController;
use App\Http\Controllers\WorkspacePageController;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Study\Workspaces;
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

    // A note in a workspace; its editor saves through PUT /api/v1/notes/{id}.
    Route::get('/workspaces/{workspace}/notes/{note}', NotePageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'note' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.notes.show');

    // A workspace and its sections (docs/specs/workspaces.md).
    Route::get('/workspaces/{workspace}/{section?}', WorkspacePageController::class)
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->whereIn('section', array_column(Workspaces::SECTIONS, 0))
        ->name('workspaces.show');

    // One entry (App\Livewire\Journal\EntryShow). Another learner's ID answers 404, like a missing one.
    Route::view('/journal/{entry}', 'journal.show')->where('entry', '[A-Za-z0-9._:-]{1,64}')->name('journal.show');
});
