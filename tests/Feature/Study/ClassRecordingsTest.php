<?php

declare(strict_types=1);

namespace Tests\Feature\Study;

use App\Platform\Access\LearnerScope;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Files\FileCipher;
use App\Platform\Files\PrivateFiles;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A class recording is kept in order, and a repeated piece is kept once. */
class ClassRecordingsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_recording_waits_for_permission_then_keeps_each_piece_once(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture', 'held_on' => '2026-10-14']);
        $recordings = app(ClassRecordings::class);

        try {
            $recordings->start($by, $class->id);
            $this->fail('Recording started before permission.');
        } catch (Unprocessable $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }

        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $this->assertSame($part->id, $recordings->start($by, $class->id)->id);

        $first = $this->piece('hello');
        $again = $recordings->accept($by, $class->id, $part->id, 1, $first, 'audio/webm;codecs=opus');
        $duplicate = $recordings->accept($by, $class->id, $part->id, 1, $this->piece('HELLO!'), 'audio/webm');
        $this->assertSame(1, $again->chunks);
        $this->assertSame(5, $duplicate->bytes);
        $this->assertSame('webm', $duplicate->extension);

        try {
            $recordings->accept($by, $class->id, $part->id, 3, $this->piece('skip'), 'audio/webm');
            $this->fail('A gap in the recording was accepted.');
        } catch (Unprocessable $e) {
            $this->assertArrayHasKey('index', $e->details['fields']);
        }

        $recordings->accept($by, $class->id, $part->id, 2, $this->piece(' world'), 'audio/webm');
        $done = $recordings->finish($by, $class->id, $part->id, 12);

        $this->assertNotNull($done->finishedAt);
        $this->assertNotNull($done->path);
        $this->assertSame(12, $done->durationSeconds);
        $this->assertStringStartsWith(FileCipher::MAGIC, Files::disk()->get($done->path));
        $this->assertSame('hello world', PrivateFiles::bytes(LearnerScope::of($by), $done->path, 100));
        $this->assertSame(ClassSessions::RECORDED, app(ClassSessions::class)->find($by, $class->id)->status);
        $this->assertSame('Part 1 · 12 s', $done->line());
        $this->assertFalse(Files::disk()->exists($done->path.'.chunks'));

        $grace = $this->principal($this->student());
        $this->expectException(NotFound::class);
        $recordings->find($grace, $class->id, $part->id);
    }

    public function test_a_piece_can_be_posted_and_an_interrupted_part_can_be_discarded(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);

        $this->actingAs($ada)->post(route('workspaces.modules.classes.chunks', [$course->id, $cells->id, $class->id, $part->id]), [
            'index' => '1',
            'mime' => 'audio/webm',
            'chunk' => UploadedFile::fake()->createWithContent('piece.webm', 'audio'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['chunks' => 1, 'bytes' => 5]);

        $this->actingAs($ada)->post(route('workspaces.modules.classes.chunks', [$course->id, $cells->id, $class->id, $part->id]), [
            'index' => '1',
            'mime' => 'audio/webm',
            'chunk' => UploadedFile::fake()->createWithContent('piece.webm', 'audio!!'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['chunks' => 1, 'bytes' => 5]);

        $shown = $recordings->forClass($by, $class->id);
        $this->assertSame(['Part 1 · not finished'], array_map(fn ($row) => $row->line(), $shown));

        $recordings->discard($by, $class->id, $part->id);
        $this->assertSame([], $recordings->forClass($by, $class->id));
        $this->assertNull($recordings->find($by, $class->id, $part->id)->path);
    }

    public function test_an_interrupted_take_can_be_kept_with_the_length_the_browser_reported(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);

        $this->actingAs($ada)->post(route('workspaces.modules.classes.chunks', [$course->id, $cells->id, $class->id, $part->id]), [
            'index' => '1',
            'mime' => 'audio/webm',
            'elapsed' => '5',
            'chunk' => UploadedFile::fake()->createWithContent('piece.webm', 'one'),
        ], ['Accept' => 'application/json'])->assertOk();
        $recordings->accept($by, $class->id, $part->id, 2, $this->piece('two'), 'audio/webm', 11);
        // A later piece never makes the take shorter.
        $recordings->accept($by, $class->id, $part->id, 3, $this->piece('three'), 'audio/webm', 4);

        $kept = $recordings->recover($by, $class->id, $part->id);

        $this->assertNotNull($kept->finishedAt);
        $this->assertSame(11, $kept->durationSeconds);
        $this->assertSame('onetwothree', PrivateFiles::bytes(LearnerScope::of($by), (string) $kept->path, 100));
        $class = app(ClassSessions::class)->find($by, $class->id);
        $this->assertSame(ClassSessions::RECORDED, $class->status);
        $this->assertSame(11, $class->durationSeconds);
        $this->assertSame($kept->id, $recordings->recover($by, $class->id, $part->id)->id);
    }

    public function test_a_take_that_never_reported_a_length_is_counted_by_its_pieces(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $part->id, 1, $this->piece('a'), 'audio/webm');
        $recordings->accept($by, $class->id, $part->id, 2, $this->piece('b'), 'audio/webm');

        $this->assertSame(2 * ClassRecordings::PIECE_SECONDS, $recordings->recover($by, $class->id, $part->id)->durationSeconds);
    }

    public function test_a_discarded_take_gives_its_storage_back(): void
    {
        config(['vistud.files.quota_bytes' => 10]);
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);

        $first = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $first->id, 1, $this->piece('123456789'), 'audio/webm');
        $recordings->discard($by, $class->id, $first->id);

        $second = $recordings->start($by, $class->id);
        $this->assertSame(9, $recordings->accept($by, $class->id, $second->id, 1, $this->piece('123456789'), 'audio/webm')->bytes);
    }

    public function test_two_takes_stay_separate_files_on_one_clock(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $course->id, $cells->id, ['title' => 'Week 3 lecture']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);

        $first = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $first->id, 1, $this->piece('hello'), 'audio/webm');
        $this->actingAs($ada)->get(route('workspaces.modules.classes.audio', [$course->id, $cells->id, $class->id, $first->id]))->assertNotFound();
        $recordings->finish($by, $class->id, $first->id, 600);

        $second = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $second->id, 1, $this->piece(' world'), 'audio/webm');
        $recordings->finish($by, $class->id, $second->id, 120);

        $timeline = $recordings->timeline($by, $class->id);
        $this->assertSame([$first->id, $second->id], array_column($timeline, 'id'));
        $this->assertSame([0, 600], array_column($timeline, 'offset'));
        $this->assertNotSame($first->path, $recordings->find($by, $class->id, $second->id)->path);
        $this->assertSame('hello', PrivateFiles::bytes(LearnerScope::of($by), (string) $recordings->playable($by, $class->id, $first->id)->path, 100));
        $this->assertSame(' world', PrivateFiles::bytes(LearnerScope::of($by), (string) $recordings->playable($by, $class->id, $second->id)->path, 100));

        $response = $this->actingAs($ada)->get(route('workspaces.modules.classes.audio', [$course->id, $cells->id, $class->id, $first->id]));
        $response->assertOk();
        $response->assertHeader('content-type', 'audio/webm');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertStreamedContent('hello');

        $this->actingAs($this->student())->get(route('workspaces.modules.classes.audio', [$course->id, $cells->id, $class->id, $first->id]))->assertNotFound();
    }

    private function piece(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'class');
        file_put_contents($path, $contents);

        return (string) $path;
    }
}
