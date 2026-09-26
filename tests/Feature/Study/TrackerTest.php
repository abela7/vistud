<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Findings;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\Links;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Findings, web links, assignments and tasks, and instructions (docs/specs/study-memory.md §3, step 1b). */
class TrackerTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1: Relational model']);
    }

    public function test_findings_are_pinned_to_a_topic_and_cite_a_note_of_the_course(): void
    {
        $findings = app(Findings::class);
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $note = app(Notes::class)->create($this->by, 'module', $this->week1->id, 'Lecture 3');

        $first = $findings->add($this->by, $joins->id, ['text' => ' A left join keeps  every row of the left table. ', 'source' => "note:{$note->id}", 'locator' => 'slide 12']);
        $findings->add($this->by, $joins->id, ['text' => 'An inner join keeps only matches.', 'locator' => 'ignored without a source'], 'ai');

        $this->assertSame(['A left join keeps every row of the left table.', 'student', 'Lecture 3', 'slide 12'], [$first->text, $first->author, $first->sourceName, $first->locator]);
        $this->assertSame(route('workspaces.notes.show', [$this->databases->id, $note->id]), $first->sourceUrl());
        $listed = $findings->byTopic($this->by, $this->databases->id)[$joins->id];
        $this->assertSame([['student', 'Lecture 3'], ['ai', null]], array_map(fn ($f) => [$f->author, $f->sourceName], $listed));
        $this->assertNull($listed[1]->locator);

        $findings->update($this->by, $first->id, ['text' => 'LEFT JOIN keeps the left rows.', 'source' => '']);
        $this->assertSame(['LEFT JOIN keeps the left rows.', null, null], [$findings->find($this->by, $first->id)->text, $findings->find($this->by, $first->id)->source, $findings->find($this->by, $first->id)->locator]);

        // A note in the trash can't be cited, and one that goes there stops showing.
        $findings->update($this->by, $first->id, ['text' => 'LEFT JOIN keeps the left rows.', 'source' => "note:{$note->id}"]);
        app(Notes::class)->trash($this->by, $note->id);
        $this->assertNull($findings->find($this->by, $first->id)->sourceUrl());
        $this->assertThrows(fn () => $findings->add($this->by, $joins->id, ['text' => 'x', 'source' => "note:{$note->id}"]), Unprocessable::class);
        $this->assertThrows(fn () => $findings->add($this->by, $joins->id, ['text' => ' ']), Unprocessable::class);

        $findings->delete($this->by, $first->id);
        $this->assertCount(1, $findings->byTopic($this->by, $this->databases->id)[$joins->id]);

        // A retired topic's findings leave the screens.
        app(Topics::class)->retire($this->by, $joins->id);
        $this->assertSame([], $findings->byTopic($this->by, $this->databases->id));
        $this->assertThrows(fn () => $findings->add($this->by, $joins->id, ['text' => 'x']), NotFound::class);
    }

    public function test_links_are_web_addresses_kept_in_places_and_count_as_contents(): void
    {
        $links = app(Links::class);
        $folder = app(Folders::class)->create($this->by, 'module', $this->week1->id, 'Videos');

        $video = $links->add($this->by, 'folder', $folder->id, ['url' => 'www.youtube.com/watch?v=joins']);
        $this->assertSame(['youtube.com', 'https://www.youtube.com/watch?v=joins', $this->week1->id, $folder->id], [$video->title, $video->url, $video->moduleId, $video->folderId]);

        foreach (['', 'javascript:alert(1)', 'ftp://example.com/file', 'https://', 'not a url'] as $bad) {
            $this->assertThrows(fn () => $links->add($this->by, 'workspace', $this->databases->id, ['url' => $bad]), Unprocessable::class);
        }

        $links->update($this->by, $video->id, ['title' => 'Joins explained', 'url' => 'https://www.youtube.com/watch?v=joins']);
        $this->assertSame('Joins explained', $links->find($this->by, $video->id)->title);

        // A folder with a link isn't empty; moving the folder carries the link.
        $this->assertThrows(fn () => app(Folders::class)->delete($this->by, $folder->id), Conflict::class);
        app(Folders::class)->move($this->by, $folder->id, 'workspace', $this->databases->id);
        $this->assertNull($links->find($this->by, $video->id)->moduleId);

        $links->move($this->by, $video->id, 'module', $this->week1->id);
        $this->assertSame([$this->week1->id, null], [$links->find($this->by, $video->id)->moduleId, $links->find($this->by, $video->id)->folderId]);
        $this->assertThrows(fn () => app(Modules::class)->delete($this->by, $this->week1->id), Conflict::class);

        $links->delete($this->by, $video->id);
        $this->assertSame([], $links->list($this->by, $this->databases->id));
    }

    public function test_assignments_and_tasks_keep_their_status_and_are_journal_records(): void
    {
        Carbon::setTestNow('2026-10-01 10:00');
        $activities = app(Activities::class);

        $essay = $activities->create($this->by, $this->databases->id, ['kind' => 'assignment', 'title' => 'ER diagram', 'due_on' => '2026-10-03', 'module_id' => $this->week1->id]);
        $activities->create($this->by, $this->databases->id, ['kind' => 'other', 'title' => 'Revise joins']);
        $exam = $activities->create($this->by, $this->databases->id, ['kind' => 'exam', 'title' => 'Midterm', 'due_on' => '2026-10-02']);

        $this->assertSame(['todo', 'Assignment', 'Due Sat 3 Oct', false], [$essay->status, $essay->kindLabel(), $essay->dueWords(), $essay->overdue()]);
        $this->assertSame(['Midterm', 'ER diagram', 'Revise joins'], $this->titles());

        $activities->setStatus($this->by, $exam->id, 'done');
        $activities->setStatus($this->by, $essay->id, 'doing');
        $this->assertSame(['ER diagram', 'Revise joins', 'Midterm'], $this->titles());

        $activities->update($this->by, $essay->id, ['kind' => 'assignment', 'title' => 'ER diagram (group)', 'due_on' => '2026-09-30', 'module_id' => $this->week1->id]);
        $late = $activities->find($this->by, $essay->id);
        $this->assertSame(['doing', true, 'Was due 30 Sep'], [$late->status, $late->overdue(), $late->dueWords()]);

        foreach ([['title' => ''], ['title' => 'x', 'kind' => 'party'], ['title' => 'x', 'due_on' => '2026-02-30']] as $bad) {
            $this->assertThrows(fn () => $activities->create($this->by, $this->databases->id, $bad), Unprocessable::class);
        }

        $records = array_values(array_filter(
            app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)),
            fn ($e) => $e->kind->value === 'record' && $e->body['record_type'] === 'activity' && $e->body['record_id'] === $essay->id,
        ));
        $this->assertSame([1, 2, 3], array_map(fn ($e) => $e->body['revision'], $records));
        $this->assertSame(['assignment', 'ER diagram (group)', '2026-09-30', 'doing', 'active'], [
            end($records)->body['kind'], end($records)->body['title'], end($records)->body['due_on'], end($records)->body['progress'], end($records)->body['status'],
        ]);

        // Deleting the module leaves the task in the course.
        app(Modules::class)->delete($this->by, $this->week1->id);
        $this->assertNull($activities->find($this->by, $essay->id)->moduleId);

        $activities->delete($this->by, $essay->id);
        $this->assertThrows(fn () => $activities->find($this->by, $essay->id), NotFound::class);
    }

    public function test_instructions_are_written_once_per_student_course_and_module(): void
    {
        $instructions = app(Instructions::class);
        $instructions->set($this->by, 'me', "I'm a second-year student. Use examples from biology.");
        $instructions->set($this->by, "workspace:{$this->databases->id}", 'Teach slide by slide, then quiz me.');
        $instructions->set($this->by, "module:{$this->week1->id}", str_repeat('a', 10));

        $this->assertSame([
            'me' => "I'm a second-year student. Use examples from biology.",
            'workspace' => 'Teach slide by slide, then quiz me.',
            'module' => 'aaaaaaaaaa',
        ], $instructions->forSession($this->by, $this->databases->id, $this->week1->id));

        $instructions->set($this->by, 'me', '  ');
        $this->assertSame('', $instructions->get($this->by, 'me'));
        $this->assertThrows(fn () => $instructions->set($this->by, 'me', str_repeat('a', 2001)), Unprocessable::class);

        app(Modules::class)->delete($this->by, $this->week1->id);
        $this->assertThrows(fn () => $instructions->get($this->by, "module:{$this->week1->id}"), NotFound::class);
    }

    public function test_another_students_things_are_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $topic = app(Topics::class)->create($this->principal($bob), $theirs->id, 'Secret');
        $link = app(Links::class)->add($this->principal($bob), 'workspace', $theirs->id, ['url' => 'https://example.com']);
        $task = app(Activities::class)->create($this->principal($bob), $theirs->id, ['title' => 'Theirs']);
        $mine = app(Topics::class)->create($this->by, $this->databases->id, 'Joins');
        $theirNote = app(Notes::class)->create($this->principal($bob), 'workspace', $theirs->id, 'Theirs');

        foreach ([
            fn () => app(Findings::class)->add($this->by, $topic->id, ['text' => 'Mine']),
            fn () => app(Links::class)->add($this->by, 'workspace', $theirs->id, ['url' => 'https://example.com']),
            fn () => app(Links::class)->delete($this->by, $link->id),
            fn () => app(Links::class)->move($this->by, $link->id, 'workspace', $this->databases->id),
            fn () => app(Activities::class)->setStatus($this->by, $task->id, 'done'),
            fn () => app(Activities::class)->create($this->by, $theirs->id, ['title' => 'Mine']),
            fn () => app(Instructions::class)->set($this->by, "workspace:{$theirs->id}", 'Mine'),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFound::class);
        }
        // Their note is not a source of mine.
        $this->assertThrows(fn () => app(Findings::class)->add($this->by, $mine->id, ['text' => 'x', 'source' => "note:{$theirNote->id}"]), Unprocessable::class);
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(fn ($a) => $a->title, app(Activities::class)->list($this->by, $this->databases->id));
    }
}
