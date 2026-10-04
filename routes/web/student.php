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

// The old address of every course page, /workspaces/…, leads to /courses/… (bookmarks, links in notes, an old tab), with its query.
Route::get('/workspaces/{path?}', function (Request $request, ?string $path = null) {
    $query = $request->getQueryString();

    return redirect('/courses'.($path === null || $path === '' ? '' : '/'.$path).($query !== null && $query !== '' ? '?'.$query : ''), 301);
})->where('path', '.*');
Route::redirect('/courses', '/', 301);

Route::middleware('auth')->group(function () {
    // Settings, one page with four parts: the AI engine (the student's key, models, limits and consent: docs/specs/study-memory.md §6),
    // Appearance, Security and Your data (docs/specs/vistud-2-blueprint.md §3.4). The old address of the first still works.
    Route::get('/settings', function (Request $request) {
        $part = in_array($request->query('part'), ['ai', 'appearance', 'security', 'data'], true) ? $request->query('part') : 'ai';

        return view('settings.index', ['part' => $part, 'twoFactor' => $request->user()?->two_factor_confirmed_at !== null]);
    })->name('settings');
    Route::redirect('/ai-engine', '/settings?part=ai', 301)->name('engine.settings');

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
    Route::get('/courses/{workspace}/notes/new', [NotePageController::class, 'create'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.notes.create');
    // A note as a PDF, Word, Markdown or text file to keep, share, or open elsewhere.
    Route::get('/courses/{workspace}/notes/{note}/export/{format}', NoteExportController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'note' => '[A-Za-z0-9-]{1,64}', 'format' => 'pdf|docx|md|txt'])
        ->name('workspaces.notes.export');
    // A note in a workspace; its editor saves through PUT /api/v1/notes/{id}.
    Route::get('/courses/{workspace}/notes/{note}', NotePageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'note' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.notes.show');

    // Embedded note images (served with learner authentication).
    Route::get('/notes/images/{id}', [NoteImageController::class, 'show'])
        ->where('id', '[A-Za-z0-9-]{1,64}')
        ->name('notes.images.show');

    // An uploaded file's page, and its bytes (shown or downloaded) after the owner check.
    Route::get('/courses/{workspace}/files/{file}', FilePageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'file' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.files.show');
    Route::get('/files/{file}/content', FileContentController::class)->where('file', '[A-Za-z0-9-]{1,64}')->name('files.content');
    // A Word, PowerPoint or Excel file as a PDF to show (App\Study\FilePreviews).
    Route::get('/files/{file}/preview', FilePreviewController::class)->where('file', '[A-Za-z0-9-]{1,64}')->name('files.preview');

    // A study session and its clock (docs/specs/study-memory.md §4).
    Route::get('/courses/{workspace}/sessions/{session}', SessionPageController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'session' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.sessions.show');
    Route::get('/courses/{workspace}/sessions/{session}/briefing', SessionBriefingController::class)
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'session' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.sessions.briefing');

    // A question on a page of its own, and a new one (docs/specs/study-memory.md §3).
    Route::get('/courses/{workspace}/questions/new', [QuestionPageController::class, 'create'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.questions.create');
    Route::get('/courses/{workspace}/questions/{question}', [QuestionPageController::class, 'show'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'question' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.questions.show');

    // A module's or a folder's own page (docs/specs/workspaces.md).
    Route::get('/courses/{workspace}/modules/{module}', [PlacePageController::class, 'module'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.show');
    Route::get('/courses/{workspace}/modules/{module}/questions', [PlacePageController::class, 'questions'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.questions');
    Route::get('/courses/{workspace}/modules/{module}/sessions', [PlacePageController::class, 'sessions'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'module' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.modules.sessions');
    Route::get('/courses/{workspace}/folders/{folder}', [PlacePageController::class, 'folder'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'folder' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.folders.show');

    // Reviewing a workspace's flashcards (docs/specs/study-memory.md §4.5).
    Route::get('/courses/{workspace}/flashcards/review', FlashcardReviewController::class)
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->name('workspaces.flashcards.review');

    // Assignments (the owner's review, 2026-10-02): a new one, and one on a page of its own with its files.
    Route::get('/courses/{workspace}/assignments/new', [AssignmentPageController::class, 'create'])
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->name('workspaces.assignments.create');
    Route::get('/courses/{workspace}/assignments/{assignment}', [AssignmentPageController::class, 'show'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'assignment' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.assignments.show');

    // A section of an assignment's plan: a new one, its own page (its tasks, files and notes), and changing it.
    Route::get('/courses/{workspace}/assignments/{assignment}/sections/new', [AssignmentPageController::class, 'createSection'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'assignment' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.assignments.sections.create');
    Route::get('/courses/{workspace}/assignments/{assignment}/sections/{plansection}', [AssignmentPageController::class, 'section'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'assignment' => '[A-Za-z0-9-]{1,64}', 'plansection' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.assignments.sections.show');
    Route::get('/courses/{workspace}/assignments/{assignment}/sections/{plansection}/edit', [AssignmentPageController::class, 'editSection'])
        ->where(['workspace' => '[A-Za-z0-9-]{1,64}', 'assignment' => '[A-Za-z0-9-]{1,64}', 'plansection' => '[A-Za-z0-9-]{1,64}'])
        ->name('workspaces.assignments.sections.edit');

    // A course and its sections (docs/specs/workspaces.md; the address is /courses since docs/specs/vistud-2-blueprint.md Phase 6).
    Route::get('/courses/{workspace}/{section?}', WorkspacePageController::class)
        ->where('workspace', '[A-Za-z0-9-]{1,64}')
        ->whereIn('section', array_column(Workspaces::SECTIONS, 0))
        ->name('workspaces.show');

    // One entry (App\Livewire\Journal\EntryShow). Another learner's ID answers 404, like a missing one.
    Route::view('/journal/{entry}', 'journal.show')->where('entry', '[A-Za-z0-9._:-]{1,64}')->name('journal.show');
});
