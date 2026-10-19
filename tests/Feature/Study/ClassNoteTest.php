<?php

declare(strict_types=1);

namespace Tests\Feature\Study;

use App\Livewire\Workspaces\ClassRecorder;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassNote;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A class has one note. Each mark is a stretch of its recording. */
class ClassNoteTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_marks_are_sections_of_the_same_note(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $part->id, 1, $this->piece('audio'), 'audio/webm');
        $recordings->finish($by, $class->id, $part->id, 1200);

        $notes = app(ClassNote::class);
        try {
            $notes->write($by, $class->id, [['from' => '10:00', 'until' => '21:00', 'about' => 'Topic 2', 'body' => 'Too late.']]);
            $this->fail('A mark past the recording was kept.');
        } catch (Unprocessable $e) {
            $this->assertArrayHasKey('until', $e->details['fields']);
        }

        $notes->write($by, $class->id, [[
            'from' => '10:00',
            'until' => '20:00',
            'about' => 'Topic 2',
            'body' => "Processes and threads.\nThe tutor asked for the diagram.",
        ]]);
        $noteId = app(ClassSessions::class)->find($by, $class->id)->noteId;
        $this->assertNotNull($noteId);

        $notes->write($by, $class->id, [
            ['from' => '0:00', 'until' => '10:00', 'about' => 'Topic 1', 'body' => 'The introduction.'],
            ['from' => '10:00', 'until' => '20:00', 'about' => 'Topic 2', 'body' => "Processes and threads.\nThe tutor asked for the diagram."],
        ]);

        $this->assertSame($noteId, app(ClassSessions::class)->find($by, $class->id)->noteId);
        $this->assertSame(1, DB::table('notes')->where('module_id', $cells->id)->count());
        $doc = app(Notes::class)->open($by, (string) $noteId)->doc;
        $this->assertIsArray($doc);
        $heading = $doc['content'][2];
        $this->assertSame('m-600-1200', $heading['attrs']['id']);
        $this->assertSame('10:00–20:00 Topic 2', $heading['content'][0]['text']);
        $this->assertSame([
            ['from' => '0:00', 'until' => '10:00', 'about' => 'Topic 1', 'body' => 'The introduction.'],
            ['from' => '10:00', 'until' => '20:00', 'about' => 'Topic 2', 'body' => "Processes and threads.\nThe tutor asked for the diagram."],
        ], $notes->read($by, $class->id));

        $grace = $this->principal($this->student());
        $this->expectException(NotFound::class);
        $notes->read($grace, $class->id);
    }

    public function test_saving_marks_keeps_what_the_student_wrote_in_the_note(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $part->id, 1, $this->piece('audio'), 'audio/webm');
        $recordings->finish($by, $class->id, $part->id, 1200);

        $notes = app(ClassNote::class);
        $notes->write($by, $class->id, [
            ['from' => '0:00', 'until' => '10:00', 'about' => 'Topic 1', 'body' => 'The introduction.'],
            ['from' => '10:00', 'until' => '20:00', 'about' => 'Topic 2', 'body' => 'Threads.'],
        ]);
        $noteId = (string) app(ClassSessions::class)->find($by, $class->id)->noteId;

        // The student opens the note and writes in it.
        $doc = app(Notes::class)->open($by, $noteId)->doc;
        $this->assertIsArray($doc);
        $content = $doc['content'];
        $list = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ask about ribosomes']]]]]]];
        $intro = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bring the textbook']]];
        array_splice($content, 2, 0, [$list]);
        array_unshift($content, $intro);
        app(Notes::class)->replace($by, $noteId, $content, 'user');

        // The class page loads the marks, and the student edits the second.
        $marks = $notes->read($by, $class->id);
        $this->assertSame("The introduction.\nAsk about ribosomes", $marks[0]['body']);
        $marks[1]['body'] = 'Threads and processes.';
        $notes->write($by, $class->id, $marks);

        $kept = app(Notes::class)->open($by, $noteId)->doc;
        $this->assertIsArray($kept);
        $this->assertSame('Bring the textbook', $kept['content'][0]['content'][0]['text']);
        $this->assertContains('bulletList', array_column($kept['content'], 'type'));
        $this->assertSame('Threads and processes.', $notes->read($by, $class->id)[1]['body']);

        $notes->write($by, $class->id, []);
        $left = app(Notes::class)->open($by, $noteId)->doc;
        $this->assertIsArray($left);
        $this->assertSame('Bring the textbook', $left['content'][0]['content'][0]['text']);
        $this->assertSame([], $notes->read($by, $class->id));
    }

    public function test_the_class_page_shows_one_recording_and_saves_a_mark(): void
    {
        $this->withoutVite();
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $part->id, 1, $this->piece('audio'), 'audio/webm');
        $recordings->finish($by, $class->id, $part->id, 1200);

        $this->actingAs($ada)->get(route('workspaces.modules.classes.show', [$course->id, $cells->id, $class->id]))
            ->assertOk()
            ->assertSee('The recording')
            ->assertSee('Use player time')
            ->assertSee('Add a note by its time')
            ->assertDontSee('Part 1');

        Livewire::actingAs($ada)
            ->test(ClassRecorder::class, ['workspaceId' => $course->id, 'moduleId' => $cells->id, 'classId' => $class->id])
            ->set('from', '10:00')
            ->set('until', '21:00')
            ->set('about', 'Topic 2')
            ->set('body', 'Too late.')
            ->call('addMark')
            ->assertHasErrors(['until' => 'That is past the end of the recording.'])
            ->set('until', '20:00')
            ->set('body', 'Processes and threads.')
            ->call('addMark')
            ->assertHasNoErrors()
            ->assertSee('10:00–20:00')
            ->assertSee('Open the note')
            ->assertSet('from', '')
            ->assertSet('marks.0.about', 'Topic 2')
            ->assertSet('marks.0.body', 'Processes and threads.');
    }

    private function piece(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'class');
        file_put_contents($path, $contents);

        return (string) $path;
    }
}
