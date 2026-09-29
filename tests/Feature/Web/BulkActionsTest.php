<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\Deck;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\QuestionBoard;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Server-side tests for bulk actions across lists in ViStud (DESIGN.md §5).
 */
class BulkActionsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->biology = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
    }

    public function test_bulk_trash_and_undo_restores_all_items(): void
    {
        $notes = app(Notes::class);
        $by = $this->principal($this->ada);
        $note1 = $notes->create($by, 'workspace', $this->biology->id, 'Note One');
        $note2 = $notes->create($by, 'workspace', $this->biology->id, 'Note Two');

        $component = Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ]);

        $component->call('bulk', 'trash', ["note:{$note1->id}", "note:{$note2->id}"])
            ->assertSee('2 items moved to the trash.');

        $this->assertNotNull($notes->find($by, $note1->id)->trashedAt);
        $this->assertNotNull($notes->find($by, $note2->id)->trashedAt);

        $component->call('undoTrash')
            ->assertSee('2 items are restored.');

        $this->assertNull($notes->find($by, $note1->id)->trashedAt);
        $this->assertNull($notes->find($by, $note2->id)->trashedAt);
    }

    public function test_bulk_restore_from_trash(): void
    {
        $notes = app(Notes::class);
        $by = $this->principal($this->ada);
        $note1 = $notes->create($by, 'workspace', $this->biology->id, 'Note One');
        $note2 = $notes->create($by, 'workspace', $this->biology->id, 'Note Two');
        $notes->trash($by, $note1->id);
        $notes->trash($by, $note2->id);

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'restore', ["note:{$note1->id}", "note:{$note2->id}"])
            ->assertSee('2 items are restored.');

        $this->assertNull($notes->find($by, $note1->id)->trashedAt);
        $this->assertNull($notes->find($by, $note2->id)->trashedAt);
    }

    public function test_bulk_destroy_from_trash(): void
    {
        $notes = app(Notes::class);
        $by = $this->principal($this->ada);
        $note1 = $notes->create($by, 'workspace', $this->biology->id, 'Note One');
        $note2 = $notes->create($by, 'workspace', $this->biology->id, 'Note Two');
        $notes->trash($by, $note1->id);
        $notes->trash($by, $note2->id);

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'destroy', ["note:{$note1->id}", "note:{$note2->id}"])
            ->assertSee('2 items are deleted.');

        $this->assertEmpty($notes->trashed($by, $this->biology->id));
    }

    public function test_bulk_move_notes(): void
    {
        $notes = app(Notes::class);
        $folders = app(Folders::class);
        $by = $this->principal($this->ada);
        $folder = $folders->create($by, 'workspace', $this->biology->id, 'Target Folder');
        $note1 = $notes->create($by, 'workspace', $this->biology->id, 'Note One');
        $note2 = $notes->create($by, 'workspace', $this->biology->id, 'Note Two');

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'move', ["note:{$note1->id}", "note:{$note2->id}"], ['destination' => "folder:{$folder->id}"])
            ->assertSee('2 items are moved.');

        $this->assertSame($folder->id, $notes->find($by, $note1->id)->folderId);
        $this->assertSame($folder->id, $notes->find($by, $note2->id)->folderId);
    }

    public function test_bulk_delete_empty_folders_and_skips_non_empty(): void
    {
        $folders = app(Folders::class);
        $notes = app(Notes::class);
        $by = $this->principal($this->ada);

        $empty = $folders->create($by, 'workspace', $this->biology->id, 'Empty Folder');
        $nonEmpty = $folders->create($by, 'workspace', $this->biology->id, 'Full Folder');
        $notes->create($by, 'folder', $nonEmpty->id, 'Inside Note');

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'delete', ["folder:{$empty->id}", "folder:{$nonEmpty->id}"])
            ->assertSee('1 item is deleted.')
            ->assertSee("1 skipped: “Full Folder” isn't empty.");

        $remainingFolders = array_map(fn ($f) => $f->id, $folders->tree($by, $this->biology->id));
        $this->assertNotContains($empty->id, $remainingFolders);
        $this->assertContains($nonEmpty->id, $remainingFolders);
    }

    public function test_bulk_delete_empty_modules_and_skips_non_empty(): void
    {
        $modules = app(Modules::class);
        $folders = app(Folders::class);
        $by = $this->principal($this->ada);

        $emptyMod = $modules->create($by, $this->biology->id, ['title' => 'Empty Mod']);
        $fullMod = $modules->create($by, $this->biology->id, ['title' => 'Full Mod']);
        $folders->create($by, 'module', $fullMod->id, 'Module Folder');

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'modules',
        ])
            ->call('bulk', 'delete', ["module:{$emptyMod->id}", "module:{$fullMod->id}"])
            ->assertSee('1 item is deleted.')
            ->assertSee("1 skipped: “Full Mod” isn't empty.");

        $remainingModules = array_map(fn ($m) => $m->id, $modules->list($by, $this->biology->id));
        $this->assertNotContains($emptyMod->id, $remainingModules);
        $this->assertContains($fullMod->id, $remainingModules);
    }

    public function test_bulk_questions_status_and_remove(): void
    {
        $questions = app(Questions::class);
        $by = $this->principal($this->ada);

        $q1 = $questions->ask($by, $this->biology->id, 'Question one?');
        $q2 = $questions->ask($by, $this->biology->id, 'Question two?');

        $component = Livewire::actingAs($this->ada)->test(QuestionBoard::class, [
            'workspaceId' => $this->biology->id,
        ]);

        $component->call('bulk', 'status', ["question:{$q1->id}", "question:{$q2->id}"], ['status' => 'answered'])
            ->assertSee('2 questions marked Answered.');

        $this->assertSame('answered', $questions->find($by, $q1->id)->status);
        $this->assertSame('answered', $questions->find($by, $q2->id)->status);

        $component->call('bulk', 'remove', ["question:{$q1->id}"])
            ->assertSee('1 question removed.');

        $remaining = array_map(fn ($q) => $q->id, $questions->list($by, $this->biology->id));
        $this->assertNotContains($q1->id, $remaining);
        $this->assertContains($q2->id, $remaining);
    }

    public function test_bulk_flashcards_remove(): void
    {
        $flashcards = app(Flashcards::class);
        $by = $this->principal($this->ada);

        $c1 = $flashcards->add($by, $this->biology->id, null, 'Front 1', 'Back 1');
        $c2 = $flashcards->add($by, $this->biology->id, null, 'Front 2', 'Back 2');

        Livewire::actingAs($this->ada)->test(Deck::class, [
            'workspaceId' => $this->biology->id,
        ])
            ->call('bulk', 'remove', ["flashcard:{$c1}", "flashcard:{$c2}"])
            ->assertSee('2 cards removed.');

        $remaining = array_map(fn ($c) => $c->id, $flashcards->list($by, $this->biology->id));
        $this->assertNotContains($c1, $remaining);
        $this->assertNotContains($c2, $remaining);
    }

    public function test_bulk_progress_topics_status_move_and_remove(): void
    {
        $topics = app(Topics::class);
        $modules = app(Modules::class);
        $by = $this->principal($this->ada);

        $cells = $modules->create($by, $this->biology->id, ['title' => 'Cells']);
        $t1 = $topics->create($by, $this->biology->id, 'Topic 1');
        $t2 = $topics->create($by, $this->biology->id, 'Topic 2');

        $component = Livewire::actingAs($this->ada)->test(Progress::class, [
            'workspaceId' => $this->biology->id,
        ]);

        $component->call('bulk', 'status', ["topic:{$t1->id}", "topic:{$t2->id}"], ['status' => 'understood'])
            ->assertSee('2 topics marked Understood.');
        $this->assertSame('understood', $topics->find($by, $t1->id)->status);

        $component->call('bulk', 'move', ["topic:{$t1->id}"], ['moduleId' => $cells->id])
            ->assertSee('1 topic moved.');
        $this->assertSame($cells->id, $topics->find($by, $t1->id)->moduleId);

        $component->call('bulk', 'remove', ["topic:{$t2->id}"])
            ->assertSee('1 topic removed.');
        $remaining = array_map(fn ($t) => $t->id, $topics->list($by, $this->biology->id));
        $this->assertNotContains($t2->id, $remaining);
    }

    public function test_bulk_overview_tasks_mark_done_and_delete(): void
    {
        $activities = app(Activities::class);
        $by = $this->principal($this->ada);

        $task1 = $activities->create($by, $this->biology->id, ['title' => 'Task 1', 'kind' => 'assignment']);
        $task2 = $activities->create($by, $this->biology->id, ['title' => 'Task 2', 'kind' => 'quiz']);

        $component = Livewire::actingAs($this->ada)->test(Tasks::class, [
            'workspaceId' => $this->biology->id,
        ]);

        $component->call('bulk', 'status', ["task:{$task1->id}", "task:{$task2->id}"], ['status' => 'done'])
            ->assertSee('2 tasks marked done.');
        $this->assertSame('done', $activities->find($by, $task1->id)->status);

        $component->call('bulk', 'delete', ["task:{$task1->id}"])
            ->assertSee('1 task deleted.');
        $remaining = array_map(fn ($t) => $t->id, $activities->list($by, $this->biology->id));
        $this->assertNotContains($task1->id, $remaining);
        $this->assertContains($task2->id, $remaining);
    }

    public function test_bulk_validation_rejects_over_limit_keys(): void
    {
        $keys = array_map(fn ($i) => "note:item-{$i}", range(1, 201));

        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'trash', $keys)
            ->assertHasErrors('keys');
    }

    public function test_bulk_validation_rejects_invalid_key_format(): void
    {
        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'trash', ['not_a_valid_key'])
            ->assertHasErrors('keys');
    }

    public function test_bulk_validation_rejects_disallowed_type(): void
    {
        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])
            ->call('bulk', 'trash', ['question:123'])
            ->assertHasErrors('keys');
    }

    public function test_cross_user_isolation_is_maintained(): void
    {
        $bob = $this->student();
        $bobWorkspace = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Bob Space']);
        $bobNote = app(Notes::class)->create($this->principal($bob), 'workspace', $bobWorkspace->id, 'Bob Secret Note');

        // Ada attempts to bulk trash Bob's note
        Livewire::actingAs($this->ada)->test(Contents::class, [
            'workspaceId' => $this->biology->id,
            'view' => 'notes',
        ])->call('bulk', 'trash', ["note:{$bobNote->id}"]);

        // Bob's note is untouched
        $this->assertNull(app(Notes::class)->find($this->principal($bob), $bobNote->id)->trashedAt);
    }
}
