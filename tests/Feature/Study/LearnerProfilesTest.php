<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\LearnerProfiles;
use App\Study\Sessions;
use App\Study\Tutoring;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** How a student likes to learn in a course, and how it shapes a session (docs/specs/vistud-2-blueprint.md §3.5.2). */
class LearnerProfilesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private LearnerProfiles $profiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->profiles = app(LearnerProfiles::class);
    }

    public function test_nothing_is_answered_until_the_student_answers_and_every_question_is_optional(): void
    {
        $profile = $this->profiles->get($this->by, $this->workspace);

        $this->assertFalse($profile->answered());
        $this->assertSame([], LearnerProfiles::lines($profile));
        $this->assertNull(LearnerProfiles::teaching($profile));

        // Saving with nothing chosen is fine, and still nothing is answered.
        $this->assertFalse($this->profiles->save($this->by, $this->workspace, [])->answered());
    }

    public function test_the_answers_are_kept_in_the_options_order_and_checked(): void
    {
        $saved = $this->profiles->save($this->by, $this->workspace, [
            'explain' => ['diagrams', 'examples', 'examples'], 'pace' => 'small', 'check' => 'often', 'goal' => 'top', 'note' => '  I read   slowly. ',
        ]);

        $this->assertSame(['examples', 'diagrams'], $saved->explain);
        $this->assertSame(['small', 'often', 'top', 'I read slowly.'], [$saved->pace, $saved->check, $saved->goal, $saved->note]);
        $this->assertSame($saved->pace, $this->profiles->get($this->by, $this->workspace)->pace);

        foreach ([['explain' => ['visual-learner']], ['pace' => 'turbo'], ['check' => 'never'], ['goal' => 'win'], ['note' => str_repeat('n', 301)]] as $input) {
            try {
                $this->profiles->save($this->by, $this->workspace, $input);
                $this->fail('Expected a refusal.');
            } catch (Unprocessable $e) {
                $this->assertSame('validation_failed', $e->errorCode);
                $this->assertSame(array_key_first($input), array_key_first($e->details['fields']));
            }
        }
        // Answering again replaces the answers; an answer can be cleared.
        $cleared = $this->profiles->save($this->by, $this->workspace, ['pace' => 'fast']);
        $this->assertSame([[], 'fast', null, null], [$cleared->explain, $cleared->pace, $cleared->check, $cleared->goal]);
    }

    public function test_the_tutor_is_told_each_answer_in_one_short_line(): void
    {
        $profile = $this->profiles->save($this->by, $this->workspace, ['explain' => ['examples', 'diagrams'], 'pace' => 'small', 'check' => 'end', 'goal' => 'deep', 'note' => 'Use C.']);

        $this->assertSame(['Likes: examples first, diagrams', 'Pace: small steps', 'Check: at the end', 'Goal: understand deeply', 'Also: Use C.'], LearnerProfiles::lines($profile));
    }

    public function test_the_answers_become_how_a_session_teaches(): void
    {
        $teach = fn (array $input) => LearnerProfiles::teaching($this->profiles->save($this->by, $this->workspace, $input));

        $this->assertSame(['method' => 'steps', 'check_ins' => 'section', 'quiz' => 'exam', 'pace' => 'slide'], $teach(['pace' => 'small', 'check' => 'often', 'goal' => 'top']));
        $this->assertSame(['method' => 'summary', 'check_ins' => 'end', 'quiz' => 'normal', 'pace' => 'section'], $teach(['explain' => ['theory'], 'pace' => 'fast', 'check' => 'end', 'goal' => 'pass']));
        $this->assertSame(['method' => 'explain', 'check_ins' => 'none', 'quiz' => 'normal', 'pace' => 'slide'], $teach(['explain' => ['analogies'], 'check' => 'rarely']));
        // Whatever comes out is something the sessions accept.
        $this->assertSame($teach(['check' => 'rarely']), Tutoring::validated($teach(['check' => 'rarely'])));
    }

    public function test_a_session_started_without_a_choice_teaches_the_way_the_student_said(): void
    {
        $sessions = app(Sessions::class);
        $this->assertSame(Tutoring::DEFAULTS, $sessions->start($this->by, $this->workspace)->tutoring);
        $sessions->end($this->by, $sessions->current($this->by)->id);

        $this->profiles->save($this->by, $this->workspace, ['pace' => 'small', 'check' => 'rarely']);
        $started = $sessions->start($this->by, $this->workspace);
        $this->assertSame(['steps', 'none'], [$started->tutoring['method'], $started->tutoring['check_ins']]);
        $sessions->end($this->by, $started->id);

        // A choice made on starting is the student's own, whatever the profile says.
        $own = $sessions->start($this->by, $this->workspace, tutoring: ['method' => 'socratic', 'check_ins' => 'end', 'quiz' => 'easy', 'pace' => 'section']);
        $this->assertSame('socratic', $own->tutoring['method']);
    }

    public function test_the_next_session_offers_the_last_one_s_way_until_the_answers_change_after_it(): void
    {
        $sessions = app(Sessions::class);
        $this->profiles->save($this->by, $this->workspace, ['check' => 'often']);
        // No session here yet: the profile.
        $this->assertSame('section', $sessions->lastChoices($this->by, $this->workspace)['tutoring']['check_ins']);

        $first = $sessions->start($this->by, $this->workspace);
        $sessions->setTutoring($this->by, $first->id, ['method' => 'socratic', 'check_ins' => 'none', 'quiz' => 'normal', 'pace' => 'slide']);
        $sessions->end($this->by, $first->id);
        // The student's own change in that session is remembered.
        $this->assertSame(['socratic', 'none'], [$sessions->lastChoices($this->by, $this->workspace)['tutoring']['method'], $sessions->lastChoices($this->by, $this->workspace)['tutoring']['check_ins']]);

        // Answering "How you learn" again afterwards wins.
        $this->travel(2)->hours();
        $this->profiles->save($this->by, $this->workspace, ['check' => 'end']);
        $this->assertSame('end', $sessions->lastChoices($this->by, $this->workspace)['tutoring']['check_ins']);
    }

    public function test_another_students_course_is_not_found_and_a_deleted_course_takes_its_answers(): void
    {
        $this->profiles->save($this->by, $this->workspace, ['pace' => 'fast']);
        $bob = $this->principal($this->student());

        foreach ([fn () => $this->profiles->get($bob, $this->workspace), fn () => $this->profiles->save($bob, $this->workspace, ['pace' => 'small'])] as $call) {
            try {
                $call();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        app(Workspaces::class)->delete($this->by, $this->workspace);
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'learner_profiles')->count());
    }
}
