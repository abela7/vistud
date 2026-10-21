<?php

namespace Tests\Acceptance\G2;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\VistudAi;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Activities;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\ClassSessions;
use App\Study\CourseProfiles;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\LearnerProfiles;
use App\Study\Links;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Plans;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;

/**
 * G2's world (ADR 0001, "G2: Tenant isolation"). Three synthetic learners, each with a unique random marker in every
 * kind of personal text ViStud keeps, and a made-up concept of their own. A's concept is spelt close to B's, so a
 * search as A for A's own concept is also a search near B's. C has a course with the same name as B's and uploads
 * the very same file bytes (ADR 0001 rule 6: no sharing or deduplication across learners).
 *
 * ViStud has no shared courses today, so "C's shared material is reachable by all three" has nothing to test: C's
 * course is C's alone, and the suite checks that too.
 *
 * Written by the independent validator (S5). The implementer never edits these tests.
 */
trait World
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected Fake $engine;

    /** @var array<string, array{user: User, by: Principal, marker: string, concept: string, ids: array<string, string>}> */
    protected array $learners = [];

    /** @var list<string> every log line written while the world was used */
    protected array $logLines = [];

    protected function buildWorld(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        // Every outgoing prompt is kept by the fake engine; every reply carries the marker of whoever asked.
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        Log::listen(function ($event) {
            $this->logLines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        foreach (['A' => 'Zorvex', 'B' => 'Zorvax', 'C' => 'Zorvix'] as $name => $concept) {
            $this->learners[$name] = $this->learner($name, $concept);
        }
        // A fresh engine: no reply scripted for one learner may be handed to another.
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
    }

    /** One learner with their marker in every kind of personal text. */
    private function learner(string $name, string $concept): array
    {
        $user = $this->student(['name' => "Student {$name}"]);
        $by = $this->principal($user);
        $marker = 'mk'.$name.Str::lower(Str::random(14));
        $m = fn (string $what) => "{$what} {$concept} {$marker}";
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);

        $course = app(Workspaces::class)->create($by, ['name' => $name === 'A' ? $m('Course') : "Course {$concept} {$marker}", 'code' => 'C'.$name, 'term' => $m('Term')]);
        $module = app(Modules::class)->create($by, $course->id, ['title' => $m('Module'), 'about' => $m('About')]);
        $folder = app(Folders::class)->create($by, 'module', $module->id, $m('Folder'));
        $topic = app(Topics::class)->create($by, $course->id, $m('Topic'), $module->id);
        $note = app(Notes::class)->create($by, 'module', $module->id, $m('Note'));
        app(Notes::class)->append($by, $note->id, MarkdownDoc::blocks($m('Note body').' with a code line `'.$marker.'_fn()` and an error "E-'.$marker.'"'));
        $question = app(Questions::class)->ask($by, $course->id, $m('Question'), $topic->id, $module->id);
        app(Questions::class)->setStatus($by, $question->id, 'answered', $m('Answer'));
        app(Findings::class)->add($by, $topic->id, ['text' => $m('Key point')]);
        $card = app(Flashcards::class)->add($by, $course->id, $topic->id, $m('Front'), $m('Back'));
        $memory = app(TutorMemories::class)->add($by, $course->id, $m('Memory'), 'student');
        app(Instructions::class)->set($by, 'me', $m('About me'));
        app(LearnerProfiles::class)->save($by, $course->id, ['note' => $m('Profile note')]);
        app(CourseProfiles::class)->save($by, $course->id, ['about' => $m('Course about'), 'textbook' => $m('Textbook')]);
        $link = app(Links::class)->add($by, 'module', $module->id, ['title' => $m('Link'), 'url' => 'https://example.com/'.$marker]);
        $file = app(Files::class)->upload($by, 'module', $module->id, $this->temp($name === 'A' ? $m('File text') : "Shared bytes {$concept} {$marker}"), "File {$marker}.txt");

        $session = app(Sessions::class)->start($by, $course->id, $topic->id, $module->id);
        app(Sessions::class)->setCheckpoint($by, $session->id, $m('Checkpoint'));
        app(Sessions::class)->setSummary($by, $session->id, $m('Summary'));
        $this->engine->will(Fake::says($m('Tutor reply')));
        app(SessionChat::class)->send($by, $session->id, $m('Student message'));
        app(Quizzes::class)->record($by, $session->id, ['kind' => 'quiz', 'questions' => [['asked' => $m('Quiz asked'), 'answer' => $m('Quiz answer'), 'result' => 'correct']]]);
        $this->engine->will(Fake::says($m('ViStud AI reply')));
        $conversation = app(VistudAi::class)->start($by, $m('ViStud AI message'));

        $assignment = app(Activities::class)->create($by, $course->id, ['title' => $m('Assignment'), 'module_id' => $module->id]);
        $part = app(Plans::class)->addPart($by, $assignment->id, $m('Plan part'));
        app(Plans::class)->addStep($by, $assignment->id, $m('Plan step'), $part->id);
        app(AssignmentLog::class)->add($by, $assignment->id, 'understand', 'decision', $m('Log entry'));
        app(AssignmentSources::class)->add($by, $assignment->id, ['kind' => 'brief', 'title' => $m('Brief'), 'text' => $m('Brief text')]);
        $class = app(ClassSessions::class)->create($by, $course->id, $module->id, ['title' => $m('Class'), 'held_on' => '2026-10-20', 'teacher' => $m('Teacher')]);

        $id = fn ($x): string => is_object($x) ? (string) $x->id : (string) $x;

        return [
            'user' => $user,
            'by' => $by,
            'marker' => $marker,
            'concept' => $concept,
            'ids' => [
                'course' => $id($course), 'module' => $id($module), 'folder' => $id($folder), 'topic' => $id($topic),
                'note' => $id($note), 'question' => $id($question), 'card' => $id($card), 'memory' => $id($memory),
                'link' => $id($link), 'file' => $id($file), 'session' => $id($session), 'conversation' => $conversation,
                'assignment' => $id($assignment), 'section' => $id($part), 'class' => $id($class),
                'learner' => (string) $by->learnerId, 'user' => (string) $user->id,
            ],
        ];
    }

    protected function marker(string $who): string
    {
        return $this->learners[$who]['marker'];
    }

    protected function by(string $who): Principal
    {
        return $this->learners[$who]['by'];
    }

    /** @return array<string, string> */
    protected function ids(string $who): array
    {
        return $this->learners[$who]['ids'];
    }

    /**
     * The check itself: nothing produced while acting as A may carry B's or C's marker. The markers are random,
     * so a match is a leak, never a coincidence. Case-insensitive, and HTML entities are decoded first.
     */
    protected function assertNothingOfOthers(string $produced, string $where, string $actingAs = 'A'): void
    {
        $text = html_entity_decode($produced, ENT_QUOTES | ENT_HTML5);
        foreach (array_keys($this->learners) as $other) {
            if ($other === $actingAs) {
                continue;
            }
            Assert::assertStringNotContainsStringIgnoringCase($this->marker($other), $text, "{$where}: learner {$other}'s private text reached learner {$actingAs}.");
        }
    }

    /** Every prompt the fake engine was sent since $from, as one text. */
    protected function promptsSince(int $from): string
    {
        return implode("\n", array_map(fn (Request $r) => $r->system."\n".json_encode($r->messages)."\n".json_encode($r->tools), array_slice($this->engine->requests, $from)));
    }

    /** A fingerprint of every row a learner owns, to prove that A's attempts changed nothing of B's. */
    protected function footprint(string $who): string
    {
        $learner = $this->learners[$who]['by']->learnerId;
        $rows = [];
        foreach (LearnerTables::TABLES as $table) {
            if (Schema::hasColumn($table, 'learner_id')) {
                $rows[$table] = DB::table($table)->where('learner_id', $learner)->orderByRaw('1')->get()->map(fn ($row) => md5(json_encode($row)))->sort()->values()->all();
            }
        }

        return md5(json_encode($rows));
    }
}
