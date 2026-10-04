<?php

namespace Tests\Unit\Study;

use App\Study\NextStep;
use App\Study\NextStepModule;
use App\Study\NextStepState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every row of the Next rule (docs/specs/vistud-2-blueprint.md §3.3), as a pure test: a state in, one step out. */
class NextStepTest extends TestCase
{
    private function module(int $n, array $with = []): NextStepModule
    {
        return new NextStepModule(...[
            'id' => "m{$n}", 'number' => $n, 'title' => "Module {$n}", 'url' => "/modules/m{$n}",
        ] + $with);
    }

    /** @param list<NextStepModule> $modules */
    private function state(array $modules = [], array $with = []): NextStepState
    {
        return new NextStepState(...$with + [
            'today' => '2026-10-07', 'modules' => $modules, 'anchorId' => $modules[0]->id ?? null,
            'reviewUrl' => '/review', 'modulesUrl' => '/modules?new=1',
        ]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} kind, text, label, url of the step */
    private function step(NextStepState $state, ?string $moduleId = null): array
    {
        $step = NextStep::of($state, $moduleId);

        return [$step->kind, $step->text, $step->label, $step->url];
    }

    public function test_a_course_with_no_modules_says_to_add_the_first(): void
    {
        $this->assertSame(['add_module', 'Add your first module', 'Add module', '/modules?new=1'], $this->step($this->state()));
    }

    public function test_a_module_with_no_files_and_no_topics_says_to_add_its_files(): void
    {
        $this->assertSame(['add_files', "Add Module 1's files", 'Open module', '/modules/m1'], $this->step($this->state([$this->module(1)])));
        // Notes count as material too.
        $this->assertNotSame('add_files', $this->step($this->state([$this->module(1, ['materials' => 1])]))[0]);
    }

    public function test_topics_the_reader_found_say_to_add_them(): void
    {
        $step = $this->step($this->state([$this->module(1, ['materials' => 2, 'suggested' => 3, 'suggestedFrom' => 'Lecture 1.pdf'])]));

        $this->assertSame(['add_topics', 'Add the topics found in Lecture 1.pdf', 'Add topics', '/modules/m1'], $step);
        // Once some are added, the suggestions no longer come first.
        $this->assertNotSame('add_topics', $this->step($this->state([$this->module(1, ['materials' => 2, 'suggested' => 3, 'topics' => 2])]))[0]);
    }

    public function test_topics_with_none_studied_say_to_study_the_module(): void
    {
        $step = NextStep::of($this->state([$this->module(1, ['materials' => 1, 'topics' => 3, 'nextTopicId' => 't1', 'nextTopicName' => 'Processes'])]));

        $this->assertSame(['study_new', 'Study Module 1', 'Study', true, 'm1', 't1'], [$step->kind, $step->text, $step->label, $step->study, $step->moduleId, $step->topicId]);
    }

    public function test_an_open_session_comes_first_of_all(): void
    {
        $state = $this->state([$this->module(1)], ['openSession' => ['topic' => 'Threads', 'url' => '/sessions/s1'], 'cardsDue' => 40]);

        $this->assertSame(['continue', 'Continue the session on Threads', 'Continue', '/sessions/s1'], $this->step($state));
        $this->assertSame('Continue your session', $this->step($this->state([], ['openSession' => ['topic' => null, 'url' => '/sessions/s1']]))[1]);
    }

    public function test_topics_left_in_the_current_module_name_the_next_one(): void
    {
        $module = fn (int $left) => $this->module(3, ['materials' => 1, 'topics' => 7, 'understood' => 7 - $left, 'studied' => true, 'nextTopicId' => 't9', 'nextTopicName' => 'Context switching']);

        $this->assertSame('Context switching: 1 topic left in Module 3', $this->step($this->state([$module(1)]))[1]);
        $this->assertSame('Context switching: 4 topics left in Module 3', $this->step($this->state([$module(4)]))[1]);
        $step = NextStep::of($this->state([$module(1)]));
        $this->assertSame([true, 'm3', 't9', 'Study'], [$step->study, $step->moduleId, $step->topicId, $step->label]);
    }

    public function test_ten_cards_due_say_to_review_with_a_time(): void
    {
        $done = $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]);

        $this->assertSame(['review', 'Review 12 cards (5 min)', 'Review', '/review'], $this->step($this->state([$done], ['cardsDue' => 12])));
        $this->assertSame('Review 10 cards (4 min)', $this->step($this->state([$done], ['cardsDue' => 10]))[1]);
        $this->assertNotSame('review', $this->step($this->state([$done], ['cardsDue' => 9]))[0]);
    }

    public function test_a_topic_that_needs_another_look_comes_after_the_cards(): void
    {
        $done = $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]);
        $attention = [['topicId' => 't5', 'topic' => 'Deadlocks', 'moduleId' => 'm1']];

        $step = NextStep::of($this->state([$done], ['attention' => $attention]));
        $this->assertSame(['attention', 'Go over Deadlocks again', true, 'm1', 't5'], [$step->kind, $step->text, $step->study, $step->moduleId, $step->topicId]);
        $this->assertSame('review', NextStep::of($this->state([$done], ['attention' => $attention, 'cardsDue' => 20]))->kind);
    }

    public function test_a_finished_module_says_to_start_the_next_one(): void
    {
        $done = $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]);

        $this->assertSame(['next_module', 'Start Module 2', 'Open module', '/modules/m2'], $this->step($this->state([$done, $this->module(2)])));
        // The last module has no next one: the course is caught up.
        $this->assertSame('caught_up', $this->step($this->state([$done]))[0]);
    }

    public function test_a_deadline_within_three_days_comes_late_in_the_order_and_within_a_day_comes_first(): void
    {
        $done = $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]);
        $due = fn (string $title, string $on, int $hours) => ['title' => $title, 'dueOn' => $on, 'hoursLeft' => $hours, 'url' => '/assignments/a1'];

        $this->assertSame(['deadline', 'ER diagram is due on Friday', 'Open', '/assignments/a1'], $this->step($this->state([$done], ['deadlines' => [$due('ER diagram', '2026-10-09', 60)]])));
        $this->assertSame('Quiz is due tomorrow', $this->step($this->state([$done], ['deadlines' => [$due('Quiz', '2026-10-08', 60)]]))[1]);
        // Past three days it waits in Coming up; past its time it is not a next step.
        $this->assertSame('caught_up', $this->step($this->state([$done], ['deadlines' => [$due('Essay', '2026-10-20', 300)]]))[0]);
        $this->assertSame('caught_up', $this->step($this->state([$done], ['deadlines' => [$due('Late', '2026-10-06', -5)]]))[0]);

        // Within 24 hours it beats everything but an open session, even a course with no modules.
        $urgent = [$due('Lab report', '2026-10-07', 6)];
        $this->assertSame(['due_soon', 'Lab report is due today'], array_slice($this->step($this->state([], ['deadlines' => $urgent, 'cardsDue' => 30])), 0, 2));
        $this->assertSame('continue', $this->step($this->state([], ['deadlines' => $urgent, 'openSession' => ['topic' => null, 'url' => '/s']]))[0]);
        $this->assertSame('Midterm is due on 21 Oct', $this->step($this->state([$done], ['deadlines' => [$due('Midterm', '2026-10-21', 70)]]))[1]);
    }

    public function test_everything_done_says_all_caught_up(): void
    {
        $done = $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]);

        $this->assertSame(['caught_up', 'All caught up. Review cards or add a module', 'Review', '/review'], $this->step($this->state([$done])));
    }

    public function test_the_module_the_student_is_in_decides_the_module_rows(): void
    {
        $modules = [
            $this->module(1, ['materials' => 1, 'topics' => 2, 'understood' => 2, 'studied' => true]),
            $this->module(2),
        ];

        // Anchored on the first (finished): start the second. Anchored on the second (empty): add its files.
        $this->assertSame('next_module', NextStep::of($this->state($modules, ['anchorId' => 'm1']))->kind);
        $this->assertSame(['add_files', "Add Module 2's files"], array_slice($this->step($this->state($modules, ['anchorId' => 'm2'])), 0, 2));
    }

    #[DataProvider('modulePages')]
    public function test_a_module_s_own_page_asks_the_same_of_that_module_alone(array $module, string $kind, string $text): void
    {
        $other = $this->module(2, ['materials' => 1, 'topics' => 1, 'studied' => true, 'nextTopicName' => 'X']);
        // The course has cards to review and a deadline in a day; the module page doesn't mention them.
        $state = $this->state([$this->module(1, ['materials' => 1, 'topics' => 1, 'studied' => true]), $this->module(3, $module)], [
            'cardsDue' => 50, 'deadlines' => [['title' => 'Lab', 'dueOn' => '2026-10-07', 'hoursLeft' => 3, 'url' => '/a']],
        ]);

        $step = NextStep::of($state, 'm3');

        $this->assertSame([$kind, $text], [$step->kind, $step->text]);
        unset($other);
    }

    /** @return array<string, array{0: array, 1: string, 2: string}> */
    public static function modulePages(): array
    {
        return [
            'empty' => [[], 'add_files', "Add Module 3's files"],
            'topics found' => [['materials' => 1, 'suggested' => 2, 'suggestedFrom' => 'Slides.pdf'], 'add_topics', 'Add the topics found in Slides.pdf'],
            'not studied' => [['materials' => 1, 'topics' => 4], 'study_new', 'Study Module 3'],
            'topics left' => [['materials' => 1, 'topics' => 4, 'understood' => 1, 'studied' => true, 'nextTopicName' => 'Paging'], 'study_topic', 'Paging: 3 topics left in Module 3'],
            'done' => [['materials' => 1, 'topics' => 4, 'understood' => 4, 'studied' => true], 'caught_up', 'This module is up to date. Review its cards or add a topic'],
        ];
    }
}
