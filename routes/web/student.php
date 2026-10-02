<?php

use App\Brain\Store\JournalReader;
use App\Http\Controllers\Api\V1\NoteImageController;
use App\Http\Controllers\AssignmentPageController;
use App\Http\Controllers\FileContentController;
use App\Http\Controllers\FilePageController;
use App\Http\Controllers\FilePreviewController;
use App\Http\Controllers\FlashcardReviewController;
use App\Http\Controllers\NoteExportController;
use App\Http\Controllers\NotePageController;
use App\Http\Controllers\PlacePageController;
use App\Http\Controllers\QuestionPageController;
use App\Http\Controllers\SessionBriefingController;
use App\Http\Controllers\SessionPageController;
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

    // The calendar of every workspace; each workspace's own is its Calendar section.
    Route::get('/calendar', function (Request $request, PrincipalFactory $principals) {
        Guard::learner($principals->fromRequest($request));

        return view('calendar.index');
    })->name('calendar.index');

    // A new note: made by its first words (POST /api/v1/notes), never while empty.
    Route::get('/workspaces/{workspace}/notes/new', [NotePageController::class, 'create'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.notes.create');
    // A note as a PDF, Word, Markdown or text file to keep, share, or open elsewhere.
    Route::get('/workspaces/{workspace}/notes/{note}/export/{format}', NoteExportController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'note' => '[A-Za-z0-9-]{1,64}', 'format' => 'pdf|docx|md|txt'])
        ->name('workspaces.notes.export');
    // A note in a workspace; its editor saves through PUT /api/v1/notes/{id}.
    Route::get('/workspaces/{workspace}/notes/{note}', NotePageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'note' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.notes.show');

    // Embedded note images (served with learner authentication).
    Route::get('/notes/images/{id}', [NoteImageController::class, 'show'])
        ->where('id', '[A-Za-z0-9-]{1,64}')
        ->name('notes.images.show');

    // An uploaded file's page, and its bytes (shown or downloaded) after the owner check.
    Route::get('/workspaces/{workspace}/files/{file}', FilePageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'file' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.files.show');
    Route::get('/files/{file}/content', FileContentController::class)->where('file', '[A-Za-z0-9-]{1,64}')->name('files.content');
    // A Word, PowerPoint or Excel file as a PDF to show (App\Study\FilePreviews).
    Route::get('/files/{file}/preview', FilePreviewController::class)->where('file', '[A-Za-z0-9-]{1,64}')->name('files.preview');

    // A study session and its clock (docs/specs/study-memory.md §4).
    Route::get('/workspaces/{workspace}/sessions/{session}', SessionPageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'session' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.sessions.show');
    Route::get('/workspaces/{workspace}/sessions/{session}/briefing', SessionBriefingController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'session' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.sessions.briefing');

    // A question on a page of its own, and a new one (docs/specs/study-memory.md §3).
    Route::get('/workspaces/{workspace}/questions/new', [QuestionPageController::class, 'create'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.questions.create');
    Route::get('/workspaces/{workspace}/questions/{question}', [QuestionPageController::class, 'show'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'question' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.questions.show');

    // A module's or a folder's own page (docs/specs/workspaces.md).
    Route::get('/workspaces/{workspace}/modules/{module}', [PlacePageController::class, 'module'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.show');
    Route::get('/workspaces/{workspace}/modules/{module}/questions', [PlacePageController::class, 'questions'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.questions');
    Route::get('/workspaces/{workspace}/modules/{module}/sessions', [PlacePageController::class, 'sessions'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.sessions');
    Route::get('/workspaces/{workspace}/folders/{folder}', [PlacePageController::class, 'folder'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'folder' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.folders.show');

    // Reviewing a workspace's flashcards (docs/specs/study-memory.md §4.5).
    Route::get('/workspaces/{workspace}/flashcards/review', FlashcardReviewController::class)
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->name('workspaces.flashcards.review');

    // Assignments (the owner's review, 2026-10-02): a new one, and one on a page of its own with its files.
    Route::get('/workspaces/{workspace}/assignments/new', [AssignmentPageController::class, 'create'])
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->name('workspaces.assignments.create');
    Route::get('/workspaces/{workspace}/assignments/{assignment}', [AssignmentPageController::class, 'show'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'assignment' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.assignments.show');

    // A workspace and its sections (docs/specs/workspaces.md).
    Route::get('/workspaces/{workspace}/{section?}', WorkspacePageController::class)
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->whereIn('section', array_column(Workspaces::SECTIONS, 0))
        ->name('workspaces.show');

    // One entry (App\Livewire\Journal\EntryShow). Another learner's ID answers 404, like a missing one.
    Route::view('/journal/{entry}', 'journal.show')->where('entry', '[A-Za-z0-9._:-]{1,64}')->name('journal.show');
});
