<?php

namespace Tests\Acceptance\G2;

use App\Engine\Context\Stack;
use App\Engine\CourseGuide;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\AnswerFromNotes;
use App\Engine\Jobs\CardsFrom;
use App\Engine\Jobs\ChooseTopics;
use App\Engine\Jobs\ClassCheck;
use App\Engine\Jobs\Job;
use App\Engine\Jobs\NoteFromFile;
use App\Engine\Jobs\PlanAssignment;
use App\Engine\Jobs\ProfileCourse;
use App\Engine\Jobs\ReadFile;
use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Engine\SessionChat;
use App\Engine\Troubles;
use App\Engine\VistudAi;
use App\Mcp\SessionWork;
use App\Study\Briefings;
use App\Study\Sessions;
use App\Study\Workspaces;
use Closure;
use Tests\TestCase;

/**
 * G2 for what the tutor is told and what leaves for the AI: the tutor's standing context, every chat, every
 * background job (the fake engine keeps each outgoing prompt), caches filled as B and read as A, a worker that
 * runs B's and A's work in turn, and every log line written meanwhile.
 */
class ContextJobsCachesTest extends TestCase
{
    use World;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Runs $work as $who; what $who sent to the AI may carry no one else's marker. Failures of the work itself are fine. */
    private function asLearner(string $who, string $what, Closure $work): void
    {
        $from = count($this->engine->requests);
        try {
            $work();
        } catch (\Throwable) {
            // Refused or failed: whatever was sent before that still counts.
        }
        $this->assertNothingOfOthers($this->promptsSince($from), $what, $who);
    }

    /** @return list<array{string, Job}> every background job, aimed at $who's own records */
    private function jobs(string $who): array
    {
        $ids = $this->ids($who);

        return [
            ['AnswerFromNotes', new AnswerFromNotes($ids['course'], $ids['question'])],
            ['CardsFrom topic', new CardsFrom($ids['course'], 'topic', $ids['topic'])],
            ['CardsFrom note', new CardsFrom($ids['course'], 'note', $ids['note'])],
            ['ChooseTopics', new ChooseTopics($ids['course'], 'module', $ids['module'])],
            ['NoteFromFile', new NoteFromFile($ids['course'], $ids['file'])],
            ['PlanAssignment', new PlanAssignment($ids['course'], $ids['assignment'], 'plan it')],
            ['ProfileCourse', new ProfileCourse($ids['course'])],
            ['ReadFile', new ReadFile($ids['course'], $ids['file'])],
            ['UnderstandAssignment', new UnderstandAssignment($ids['course'], $ids['assignment'])],
            ['ClassCheck', new ClassCheck($ids['course'], $ids['class'])],
        ];
    }

    public function test_the_tutors_context_and_every_chat_as_a_carry_nothing_of_b_or_c(): void
    {
        $by = $this->by('A');
        $ids = $this->ids('A');
        $near = $this->learners['B']['concept'];

        $built = app(Stack::class)->build($by, app(Sessions::class)->find($by, $ids['session']), null, true);
        $this->assertNothingOfOthers($built->system.json_encode($built->tools), 'the tutor\'s standing context');
        $this->assertStringContainsString($this->marker('A'), $built->system, 'the context is A\'s own');
        $this->assertNothingOfOthers(json_encode(app(Briefings::class)->forSession($by, $ids['session'])), 'the session briefing');
        $this->assertNothingOfOthers(json_encode(app(SessionWork::class)->for($by, $ids['session'])), 'the session\'s work');

        $this->asLearner('A', 'study session chat', fn () => app(SessionChat::class)->send($by, $ids['session'], "Explain {$near} to me"));
        $this->asLearner('A', 'topic chat', fn () => app(SessionChat::class)->sendOnTopic($by, $ids['topic'], "What is {$near}?"));
        $this->asLearner('A', 'assignment chat', fn () => app(SessionChat::class)->sendOnAssignment($by, $ids['assignment'], "Plan {$near}"));
        $this->asLearner('A', 'ViStud AI', fn () => app(VistudAi::class)->send($by, $ids['conversation'], "Search all my courses for {$near}"));
        $this->asLearner('A', 'course guide', fn () => app(CourseGuide::class)->turn($by, $ids['course'], [], "Add a module on {$near}"));
    }

    public function test_every_background_job_as_a_sends_nothing_of_b_or_c_and_bs_ids_are_refused(): void
    {
        foreach ($this->jobs('A') as [$name, $job]) {
            $this->asLearner('A', "job {$name}", fn () => app(Runner::class)->start($this->by('A'), $job));
        }

        $before = [$this->footprint('B'), $this->footprint('C')];
        foreach (['B', 'C'] as $other) {
            foreach ($this->jobs($other) as [$name, $job]) {
                // A starts a job on another learner's records: nothing of theirs may go out, nothing of theirs may change.
                $this->asLearner('A', "job {$name} on {$other}'s ids", fn () => app(Runner::class)->start($this->by('A'), $job));
            }
        }
        $this->assertSame($before, [$this->footprint('B'), $this->footprint('C')], 'A\'s jobs changed another learner\'s records.');
    }

    public function test_one_worker_running_b_and_a_in_turn_keeps_them_apart(): void
    {
        $b = $this->jobs('B');
        $a = $this->jobs('A');
        foreach (array_keys($a) as $i) {
            $this->asLearner('B', "worker turn B {$b[$i][0]}", fn () => app(Runner::class)->start($this->by('B'), $b[$i][1]));
            $this->asLearner('A', "worker turn A {$a[$i][0]}", fn () => app(Runner::class)->start($this->by('A'), $a[$i][1]));
        }
    }

    public function test_caches_filled_as_b_are_never_read_as_a(): void
    {
        $b = $this->ids('B');
        $a = $this->ids('A');

        // B's caches: the topic review for B's module and B's last engine trouble, each carrying B's marker.
        $this->engine->will(Fake::says(json_encode(['topics' => [['name' => 'Cached '.$this->marker('B'), 'reason' => $this->marker('B')]]])));
        try {
            app(Runner::class)->start($this->by('B'), new ChooseTopics($b['course'], 'module', $b['module']));
        } catch (\Throwable) {
        }
        app(Troubles::class)->note($this->by('B'), 'tutor', 'fake/tutor', new EngineFailed('engine_failed', 'Trouble '.$this->marker('B')));

        // A reads the same caches, by B's ids and by A's own.
        foreach ([[$b['module'], 'B'], [$a['module'], 'A']] as [$module, $whose]) {
            foreach ([fn () => ChooseTopics::review($this->by('A'), $module, null), fn () => ChooseTopics::progress($this->by('A'), $whose === 'A' ? $a['course'] : $b['course'], $module, null)] as $i => $read) {
                try {
                    $seen = json_encode($read());
                } catch (\Throwable $e) {
                    $seen = $e->getMessage(); // Refused, as for any id that is not A's.
                }
                $this->assertNothingOfOthers((string) $seen, "topic cache read {$i} ({$whose}'s module)");
            }
        }
        $this->assertNothingOfOthers(json_encode(app(Troubles::class)->last($this->by('A'))), 'engine trouble cache');
        // A's pages that read those caches.
        $this->actingAs($this->learners['A']['user']);
        $this->assertNothingOfOthers((string) $this->get(route('workspaces.show', $a['course']))->getContent(), 'A\'s course page after B filled the caches');
        $this->assertNothingOfOthers(json_encode(app(Workspaces::class)->list($this->by('A'))), 'A\'s course list');
    }

    public function test_no_log_line_written_while_the_world_was_used_carries_anyones_private_text(): void
    {
        foreach ($this->jobs('A') as [$name, $job]) {
            try {
                app(Runner::class)->start($this->by('A'), $job);
            } catch (\Throwable) {
            }
        }
        // Logs never hold personal text at all (ADR 0001 rule 5): not even the learner's own.
        $lines = implode("\n", $this->logLines);
        foreach (['A', 'B', 'C'] as $who) {
            $this->assertStringNotContainsStringIgnoringCase($this->marker($who), $lines, "a log line carried learner {$who}'s private text");
        }
    }
}
