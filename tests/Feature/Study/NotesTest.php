<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Folders;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Notes, their versions and the trash (docs/specs/workspaces.md step 3, ADR 0003 §5.3, §9.1). */
class NotesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Notes $notes;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $biology;

    private ModuleDetails $cells;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = app(Notes::class);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        $this->cells = app(Modules::class)->create($this->by, $this->biology->id, ['title' => 'Cells']);
    }

    public function test_a_note_is_created_in_a_place_and_opens_empty(): void
    {
        $labs = app(Folders::class)->create($this->by, 'module', $this->cells->id, 'Labs');

        $inModule = $this->notes->create($this->by, 'module', $this->cells->id, 'Mitosis');
        $inFolder = $this->notes->create($this->by, 'folder', $labs->id);
        $topLevel = $this->notes->create($this->by, 'workspace', $this->biology->id, 'Exam plan');

        $this->assertSame([$this->cells->id, null], [$inModule->moduleId, $inModule->folderId]);
        $this->assertSame([$this->cells->id, $labs->id, 'Untitled note'], [$inFolder->moduleId, $inFolder->folderId, $inFolder->displayTitle()]);
        $this->assertSame([null, null, "workspace:{$this->biology->id}"], [$topLevel->moduleId, $topLevel->folderId, $topLevel->placeKey()]);

        $opened = $this->notes->open($this->by, $inModule->id);
        $this->assertSame([1, NoteDoc::empty()], [$opened->version, $opened->doc]);
        $this->assertCount(3, $this->notes->list($this->by, $this->biology->id));
    }

    public function test_each_save_is_a_new_version_and_a_retry_answers_the_same(): void
    {
        $note = $this->notes->create($this->by, 'module', $this->cells->id);

        $first = $this->notes->save($this->by, $note->id, $this->edit(1, 'Mitosis', 'Makes two cells.', 'save-0001'));
        $retry = $this->notes->save($this->by, $note->id, $this->edit(1, 'Mitosis', 'Makes two cells.', 'save-0001'));
        $same = $this->notes->save($this->by, $note->id, $this->edit(2, 'Mitosis', 'Makes two cells.', 'save-0002'));
        $second = $this->notes->save($this->by, $note->id, $this->edit(2, 'Mitosis', 'Makes two identical cells.', 'save-0003'));

        $this->assertSame([2, 2, 2, 3], [$first->version, $retry->version, $same->version, $second->version]);
        $opened = $this->notes->open($this->by, $note->id);
        $this->assertSame(['Mitosis', 'Makes two identical cells.'], [$opened->title, NoteDoc::text($opened->doc)]);
        $this->assertSame(3, $this->versions($note->id));
    }

    public function test_a_save_based_on_an_older_version_is_a_conflict_and_changes_nothing(): void
    {
        $note = $this->notes->create($this->by, 'module', $this->cells->id);
        $this->notes->save($this->by, $note->id, $this->edit(1, 'Mine', 'From my laptop.', 'laptop-01'));

        try {
            $this->notes->save($this->by, $note->id, $this->edit(1, 'Theirs', 'From my phone.', 'phone-001'));
            $this->fail('An old base version was accepted.');
        } catch (Conflict $e) {
            $this->assertSame(['version_conflict', ['current_version' => 2]], [$e->errorCode, $e->details]);
        }

        // Keeping mine is a new save on top of the current version.
        $kept = $this->notes->save($this->by, $note->id, ['kind' => 'conflict_resolution'] + $this->edit(2, 'Theirs', 'From my phone.', 'phone-002'));
        $this->assertSame(3, $kept->version);
    }

    public function test_the_document_is_checked_and_cleaned(): void
    {
        $note = $this->notes->create($this->by, 'module', $this->cells->id);
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => 'b1', 'style' => 'color: red'], 'content' => [['type' => 'text', 'text' => 'Mitosis']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Read ', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => 'this', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'that', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org', 'onclick' => 'x']]]],
                ['type' => 'text', 'text' => ''],
            ]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'x = 1', 'marks' => [['type' => 'bold']]]]],
            ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph']]]]],
        ]];

        $this->notes->save($this->by, $note->id, ['doc' => $doc] + $this->edit(1, '', '', 'clean-001'));

        $this->assertSame(['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => 'b1', 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Mitosis']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Read ', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => 'this'],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'that', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org']]]],
            ]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'x = 1']]],
            ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph']]]]],
        ]], $this->notes->open($this->by, $note->id)->doc);

        foreach ([
            ['type' => 'doc', 'content' => [['type' => 'iframe', 'attrs' => ['src' => 'https://example.org']]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'script']]]]]]],
            ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'not in a block']]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', NoteDoc::MAX_BYTES)]]]]],
            'not a document',
        ] as $i => $bad) {
            $this->assertThrows(fn () => $this->notes->save($this->by, $note->id, ['doc' => $bad] + $this->edit(2, '', '', "bad-000{$i}")), Unprocessable::class);
        }
    }

    public function test_notes_move_within_their_workspace_and_go_with_a_moved_folder(): void
    {
        $labs = app(Folders::class)->create($this->by, 'module', $this->cells->id, 'Labs');
        $note = $this->notes->create($this->by, 'folder', $labs->id, 'Lab 1');
        $division = app(Modules::class)->create($this->by, $this->biology->id, ['title' => 'Cell division']);

        app(Folders::class)->move($this->by, $labs->id, 'module', $division->id);
        $this->assertSame($division->id, $this->notes->find($this->by, $note->id)->moduleId);

        $this->notes->move($this->by, $note->id, 'workspace', $this->biology->id);
        $this->assertSame([null, null], [$this->notes->find($this->by, $note->id)->moduleId, $this->notes->find($this->by, $note->id)->folderId]);

        $maths = app(Workspaces::class)->create($this->by, ['name' => 'Mathematics']);
        $this->assertThrows(fn () => $this->notes->move($this->by, $note->id, 'workspace', $maths->id), NotFound::class);
    }

    public function test_the_trash_refuses_saves_restores_and_empties_after_30_days(): void
    {
        $labs = app(Folders::class)->create($this->by, 'module', $this->cells->id, 'Labs');
        $note = $this->notes->create($this->by, 'folder', $labs->id, 'Lab 1');
        $this->notes->save($this->by, $note->id, $this->edit(1, 'Lab 1', 'Microscopes.', 'before-01'));

        $this->notes->trash($this->by, $note->id);
        $this->assertSame([], $this->notes->list($this->by, $this->biology->id));
        $this->assertSame([$note->id], array_map(fn ($n) => $n->id, $this->notes->trashed($this->by, $this->biology->id)));
        $this->assertThrows(fn () => $this->notes->save($this->by, $note->id, $this->edit(2, 'Lab 1', 'More.', 'after-001')), Gone::class);
        // A save that went through before the note was trashed still answers the same.
        $this->assertSame(2, $this->notes->save($this->by, $note->id, $this->edit(1, 'Lab 1', 'Microscopes.', 'before-01'))->version);

        // The folder can go while the note is in the trash; restoring puts it at the module's top.
        app(Folders::class)->delete($this->by, $labs->id);
        $restored = $this->notes->restore($this->by, $note->id);
        $this->assertSame([$this->cells->id, null, null], [$restored->moduleId, $restored->folderId, $restored->trashedAt]);

        $this->assertThrows(fn () => $this->notes->destroy($this->by, $note->id), Conflict::class);
        $this->notes->trash($this->by, $note->id);
        $scope = LearnerScope::forJob($this->by->learnerId);
        $this->assertSame(0, $this->notes->purgeTrash($scope));
        $this->travel(Notes::TRASH_DAYS + 1)->days();
        $this->assertSame(1, $this->notes->purgeTrash($scope));
        $this->assertThrows(fn () => $this->notes->find($this->by, $note->id), NotFound::class);
        $this->assertSame(0, $this->versions($note->id));
        $this->assertSame(['trashed', 'restored', 'trashed', 'deleted'], DB::table('content_tombstones')->where('entity_id', $note->id)->orderBy('id')->pluck('kind')->all());
    }

    public function test_a_module_or_folder_with_notes_in_it_cannot_be_deleted(): void
    {
        $labs = app(Folders::class)->create($this->by, 'module', $this->cells->id, 'Labs');
        $note = $this->notes->create($this->by, 'folder', $labs->id, 'Lab 1');

        $this->assertThrows(fn () => app(Folders::class)->delete($this->by, $labs->id), Conflict::class);
        $this->notes->move($this->by, $note->id, 'module', $this->cells->id);
        app(Folders::class)->delete($this->by, $labs->id);
        $this->assertThrows(fn () => app(Modules::class)->delete($this->by, $this->cells->id), Conflict::class);
    }

    public function test_folders_can_sit_at_the_workspace_top_level(): void
    {
        $folders = app(Folders::class);
        $exams = $folders->create($this->by, 'workspace', $this->biology->id, 'Exam prep');
        $inModule = $folders->create($this->by, 'module', $this->cells->id, 'Labs');
        $folders->create($this->by, 'folder', $exams->id, 'Past papers');

        $this->assertSame([['Labs', "module:{$this->cells->id}"], ['Exam prep', "workspace:{$this->biology->id}"], ['Past papers', "folder:{$exams->id}"]],
            array_map(fn ($f) => [$f->name, $f->siblingsKey()], $folders->tree($this->by, $this->biology->id)));

        $folders->move($this->by, $inModule->id, 'workspace', $this->biology->id);
        $folders->reorder($this->by, $inModule->id, 0);
        $this->assertSame(['Labs', 'Exam prep', 'Past papers'], array_map(fn ($f) => $f->name, $folders->tree($this->by, $this->biology->id)));
    }

    public function test_another_students_note_is_missing(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $note = $this->notes->create($bob, 'workspace', $theirs->id, 'Secret');

        foreach ([
            fn () => $this->notes->open($this->by, $note->id),
            fn () => $this->notes->save($this->by, $note->id, $this->edit(1, 'Mine', 'Mine.', 'steal-001')),
            fn () => $this->notes->trash($this->by, $note->id),
            fn () => $this->notes->move($this->by, $note->id, 'workspace', $this->biology->id),
            fn () => $this->notes->create($this->by, 'workspace', $theirs->id),
            fn () => $this->notes->list($this->by, $theirs->id),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFound::class);
        }
        $this->assertSame('Secret', $this->notes->open($bob, $note->id)->title);
    }

    private function edit(int $base, string $title, string $text, string $saveId): array
    {
        return [
            'base_version' => $base, 'save_id' => $saveId, 'client_id' => 'tab-00001', 'title' => $title,
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]]]]],
        ];
    }

    private function versions(string $noteId): int
    {
        return DB::table('note_versions')->where('note_id', $noteId)->count();
    }
}
