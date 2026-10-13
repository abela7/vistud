<?php

namespace Tests\Feature\Study;

use App\Brain\Writer\JournalWriter;
use App\Engine\Context\Stack;
use App\Engine\Settings;
use App\Engine\Tools\Context;
use App\Engine\Tools\Effects;
use App\Engine\Tools\SetTopicStatusTool;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Rollups;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Tracking that follows every change (the owner's ask, 2026-10-12): the newest word on a topic holds until newer evidence,
 * whoever said it; studying a topic in a session is contact with it; the tutor knows where each topic stands, who said so
 * and what the evidence says, and may update a word said before the session; progress adds up what is shown, so it
 * follows at once; a session with no topic changes no topic.
 */
class TrackingTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private Topics $topics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
        $this->topics = app(Topics::class);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 1'])->id;
    }

    /** Studies for $minutes: the open page tells the session the student is there every five minutes. */
    private function study(string $sessionId, int $minutes): void
    {
        for ($left = $minutes; $left > 0; $left -= 5) {
            $this->travel(min(5, $left))->minutes();
            app(Sessions::class)->touch($this->by, $sessionId);
        }
    }

    private function shown(string $id): string
    {
        return $this->topics->find($this->by, $id)->shown();
    }

    /** Makes the evidence secure: two unaided tasks across two sessions, then explained in the student's own words. */
    private function master(string $topicId, string $suffix = ''): void
    {
        $scope = $this->learnerScopeOf($this->ada);
        app(JournalWriter::class)->appendBatch($scope, [
            $this->taskRecord("R1{$suffix}", "TK-1{$suffix}", "LAB/q1{$suffix}", '2026-10-03T08:00:00+00:00'), $this->exercises("K2{$suffix}", "TK-1{$suffix}", $topicId, '2026-10-03T08:00:00+00:00'),
            $this->taskRecord("R2{$suffix}", "TK-2{$suffix}", "LAB/q2{$suffix}", '2026-10-03T08:00:00+00:00'), $this->exercises("K3{$suffix}", "TK-2{$suffix}", $topicId, '2026-10-03T08:00:00+00:00'),
            $this->attempt($scope, "A1{$suffix}", "TK-1{$suffix}", 'correct', '2026-10-04T10:00:00+00:00'),
            $this->attempt($scope, "A2{$suffix}", "TK-2{$suffix}", 'correct', '2026-10-06T10:00:00+00:00'),
            $this->attempt($scope, "A3{$suffix}", "TK-1{$suffix}", 'correct', '2026-10-08T10:00:00+00:00', ['body' => ['form' => 'explain']]),
            $this->interpreterJudges("J1{$suffix}", "A3{$suffix}", ['own_words' => true], '2026-10-08T10:05:00+00:00'),
        ]);
    }

    public function test_every_change_of_mind_is_shown_in_both_directions(): void
    {
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $steps = [
            ['covered', 'covered'],         // started
            ['not_started', 'not_started'], // taken back: not "covered" because it was met
            ['understood', 'understood'],
            ['confused', 'confused'],       // understood, then not
            ['covered', 'covered'],         // back to studying it
            ['understood', 'understood'],
            ['not_started', 'not_started'], // all the way back
        ];
        foreach ($steps as $i => [$said, $shown]) {
            $this->travel(1)->minutes();
            $this->topics->report($this->by, $joins, $said);
            $this->assertSame($shown, $this->shown($joins), "step {$i}: {$said}");
        }
    }

    public function test_the_students_newest_word_holds_over_what_was_earned_until_newer_evidence(): void
    {
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $this->master($joins);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00:00', 'UTC'));
        $this->assertSame('mastered', $this->shown($joins));

        // They say they've lost it: believed, over the mastery.
        $this->topics->report($this->by, $joins, 'confused');
        $this->assertSame('confused', $this->shown($joins));
        // Taken back to the start: believed too.
        $this->topics->report($this->by, $joins, 'not_started');
        $this->assertSame('not_started', $this->shown($joins));

        // Newer evidence overtakes the word: a success after "not understood" is no longer "not understood".
        $normal = $this->topics->create($this->by, $this->workspace, 'Normalisation', $this->module)->id;
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
        $this->topics->report($this->by, $normal, 'confused');
        $this->master($normal, '-n');
        $this->assertSame('mastered', $this->shown($normal));
    }

    public function test_studying_a_topic_in_a_session_is_contact_with_it_and_overtakes_not_started(): void
    {
        $sessions = app(Sessions::class);
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $keys = $this->topics->create($this->by, $this->workspace, 'Keys', $this->module)->id;
        $this->topics->report($this->by, $joins, 'not_started');

        // A minute on Joins, then on to Keys: too short to count.
        $session = $sessions->start($this->by, $this->workspace, $joins, $this->module);
        $this->study($session->id, 1);
        $sessions->setTopic($this->by, $session->id, $keys);
        $this->assertSame(['not_started', 'not_started'], [$this->topics->find($this->by, $joins)->label, $this->shown($joins)]);

        // Twenty minutes on Keys, then back to Joins: Keys was met.
        $this->study($session->id, 20);
        $sessions->setTopic($this->by, $session->id, $joins);
        $this->assertSame(['introduced', 'covered'], [$this->topics->find($this->by, $keys)->label, $this->shown($keys)]);

        // Half an hour on Joins, and the session ends: met, and the "not started" said before is overtaken.
        $this->study($session->id, 30);
        $sessions->end($this->by, $session->id);
        $this->assertSame(['introduced', 'covered'], [$this->topics->find($this->by, $joins)->label, $this->shown($joins)]);
    }

    public function test_a_page_left_open_with_no_one_there_is_not_studying(): void
    {
        $sessions = app(Sessions::class);
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $session = $sessions->start($this->by, $this->workspace, $joins, $this->module);
        // A minute of study, then the page sits open for an hour with no one there.
        $this->study($session->id, 1);
        $this->travel(60)->minutes();
        $sessions->end($this->by, $session->id);

        $this->assertSame(['not_started', 'not_started'], [$this->topics->find($this->by, $joins)->label, $this->shown($joins)]);
    }

    public function test_a_session_left_to_end_by_itself_and_one_logged_afterwards_are_contact_too(): void
    {
        $sessions = app(Sessions::class);
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $keys = $this->topics->create($this->by, $this->workspace, 'Keys', $this->module)->id;

        $session = $sessions->start($this->by, $this->workspace, $joins, $this->module);
        $this->study($session->id, 15);
        // Left open: it ends by itself hours later, up to the last activity.
        $this->travel(Sessions::AUTO_END_HOURS + 1)->hours();
        $this->assertSame('ended', $sessions->find($this->by, $session->id)->state);
        $this->assertSame('covered', $this->shown($joins));

        $sessions->log($this->by, $this->workspace, ['date' => '2026-10-01', 'time' => '18:00', 'minutes' => '45', 'topic_id' => $keys], 'UTC');
        $this->assertSame('covered', $this->shown($keys));
    }

    public function test_a_session_with_no_topic_changes_no_topic_and_its_time_stays_the_modules(): void
    {
        $sessions = app(Sessions::class);
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $session = $sessions->start($this->by, $this->workspace, null, $this->module);
        $this->study($session->id, 40);
        $ended = $sessions->end($this->by, $session->id);

        $this->assertSame(['not_started', 'not_started'], [$this->topics->find($this->by, $joins)->label, $this->shown($joins)]);
        $this->assertNull($ended->topicId);
        $this->assertSame($this->module, $ended->moduleId);
        $this->assertGreaterThan(0, $ended->studySeconds);
    }

    public function test_the_tutor_updates_a_word_said_before_the_session_but_not_one_said_in_it_and_can_be_undone(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $keys = $this->topics->create($this->by, $this->workspace, 'Keys', $this->module)->id;
        $this->topics->report($this->by, $joins, 'confused');
        $this->travel(2)->days();

        $session = app(Sessions::class)->start($this->by, $this->workspace, $joins, $this->module);
        $this->travel(5)->minutes();
        $this->topics->report($this->by, $keys, 'confused');
        $tool = app(SetTopicStatusTool::class);
        $context = fn () => new Context($this->workspace, $this->module, $session->id, 'UTC', effects: new Effects);

        // Said two days ago: what the tutor saw now is newer. Shown as the tutor's.
        $said = $tool->run($this->by, $context(), ['topic' => 'Joins', 'status' => 'understood', 'reason' => 'Explained a left join correctly.']);
        $this->assertStringStartsWith('Marked "Joins" as understood', $said);
        $this->assertSame(['understood', 'tutor'], [$this->shown($joins), $this->topics->find($this->by, $joins)->statusBy]);

        // Said in this session: the student's word stands; the tutor's view is only proposed.
        $said = $tool->run($this->by, $context(), ['topic' => 'Keys', 'status' => 'understood', 'reason' => 'Got one right.']);
        $this->assertStringContainsString('so it stays', $said);
        $this->assertSame(['confused', 'student'], [$this->shown($keys), $this->topics->find($this->by, $keys)->statusBy]);
    }

    public function test_the_tutor_knows_where_each_topic_stands_who_said_so_and_the_evidence(): void
    {
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $this->topics->create($this->by, $this->workspace, 'Keys', $this->module);
        $this->topics->report($this->by, $joins, 'understood');
        $this->travel(3)->days();
        $session = app(Sessions::class)->start($this->by, $this->workspace, $joins, $this->module);

        $system = app(Stack::class)->build($this->by, $session, null, true)->system;
        $this->assertStringContainsString('- Joins: understood: the student said so 3 days ago; evidence: no contact yet · not practised yet', $system);
        $this->assertStringContainsString('- Keys: not started', $system);
    }

    public function test_the_tutor_sees_the_time_on_the_topic_so_far_and_the_default_is_not_the_students_word(): void
    {
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $session = app(Sessions::class)->start($this->by, $this->workspace, $joins, $this->module);
        $this->study($session->id, 15);

        $system = app(Stack::class)->build($this->by, app(Sessions::class)->find($this->by, $session->id), null, true)->system;
        // Not recorded yet (that is on ending or switching), but the tutor knows it has been teaching it.
        $this->assertStringContainsString('Topic now: Joins (not started, nobody has said yet; practice: no contact yet; on it this session: 15 min, recorded as studying it when the topic changes or the session ends).', $system);
        $this->assertStringContainsString('- Joins: not started (nothing said or studied yet)', $system);
        $this->assertStringContainsString('Statuses: what you set with set_topic_status is applied at once and shown as yours; the student can undo it.', $system);
    }

    public function test_progress_adds_up_what_is_shown_so_it_follows_every_change_at_once(): void
    {
        $joins = $this->topics->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $this->topics->create($this->by, $this->workspace, 'Keys', $this->module);
        $understood = function (): array {
            $module = app(Rollups::class)->for($this->by, $this->workspace)->modules[0];

            return [count(array_filter($module->topics, fn ($t) => in_array($t->shown, ['understood', 'mastered'], true))), count($module->topics)];
        };

        $this->assertSame([0, 2], $understood());
        $this->topics->report($this->by, $joins, 'understood');
        $this->assertSame([1, 2], $understood());
        $this->topics->report($this->by, $joins, 'confused');
        $this->assertSame([0, 2], $understood(), 'understood, then not: it no longer counts');
        $this->topics->create($this->by, $this->workspace, 'Indexes', $this->module);
        $this->assertSame([0, 3], $understood(), 'a topic added counts at once');
    }
}
