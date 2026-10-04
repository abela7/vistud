<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The topics the reader found, until the student adds or dismisses them (docs/specs/vistud-2-blueprint.md §3.5.3). */
class TopicSuggestionsTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week3;

    private string $week4;

    private TopicSuggestions $suggestions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->week3 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $this->week4 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 4'])->id;
        $this->suggestions = app(TopicSuggestions::class);
    }

    private function names(string $moduleId): array
    {
        return array_map(fn ($s) => $s->name, $this->suggestions->list($this->by, $moduleId));
    }

    public function test_new_names_wait_and_those_the_module_has_or_has_had_suggested_do_not(): void
    {
        app(Topics::class)->create($this->by, $this->workspace, 'Processes', $this->week3);
        app(Topics::class)->create($this->by, $this->workspace, 'Memory', $this->week4);

        $this->assertSame(3, $this->suggestions->suggest($this->by, $this->week3, null, ['processes', 'CPU scheduling', 'cpu  scheduling', 'Round robin', '', str_repeat('x', 121), 'Memory']));

        // Existing topics (any case) and repeats are left out; the same name in another module is its own.
        $this->assertSame(['CPU scheduling', 'Round robin', 'Memory'], $this->names($this->week3));
        $this->assertSame(0, $this->suggestions->suggest($this->by, $this->week3, null, ['CPU SCHEDULING']));
        $this->assertSame(1, $this->suggestions->suggest($this->by, $this->week4, null, ['CPU scheduling', 'Memory']));
    }

    public function test_where_they_wait_and_in_which_file_they_were_found(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->week3, $this->temp("Slides.\n"), 'Lecture 3.txt');
        $this->suggestions->suggest($this->by, $this->week3, $file->id, ['CPU scheduling', 'Round robin']);
        $this->suggestions->suggest($this->by, $this->week4, null, ['Paging']);

        $this->assertSame(['CPU scheduling', 'Lecture 3.txt'], [$this->suggestions->list($this->by, $this->week3)[0]->name, $this->suggestions->list($this->by, $this->week3)[0]->sourceName]);
        $this->assertSame([$this->week3 => ['count' => 2, 'from' => 'Lecture 3.txt'], $this->week4 => ['count' => 1, 'from' => null]], $this->suggestions->waiting($this->by, $this->workspace));
    }

    public function test_add_all_makes_them_topics_of_the_module_in_order_and_they_are_not_suggested_again(): void
    {
        $this->suggestions->suggest($this->by, $this->week3, null, ['CPU scheduling', 'Round robin', 'Deadlocks']);

        $this->assertSame(3, $this->suggestions->addAll($this->by, $this->week3));

        $topics = app(Topics::class)->list($this->by, $this->workspace);
        $this->assertSame([['CPU scheduling', $this->week3], ['Round robin', $this->week3], ['Deadlocks', $this->week3]], array_map(fn ($t) => [$t->name, $t->moduleId], $topics));
        $this->assertSame([], $this->names($this->week3));
        $this->assertSame(0, $this->suggestions->addAll($this->by, $this->week3));
        // A topic that was added stays known even if the student deletes it later.
        $this->assertSame(0, $this->suggestions->suggest($this->by, $this->week3, null, ['Deadlocks']));
    }

    public function test_one_can_be_picked_and_the_rest_dismissed_for_good(): void
    {
        $this->suggestions->suggest($this->by, $this->week3, null, ['CPU scheduling', 'Round robin', 'Deadlocks']);
        $list = $this->suggestions->list($this->by, $this->week3);

        $this->assertSame(1, $this->suggestions->add($this->by, $list[1]->id));
        $this->suggestions->dismiss($this->by, $list[0]->id);
        $this->assertSame(['Deadlocks'], $this->names($this->week3));
        $this->suggestions->dismissAll($this->by, $this->week3);

        $this->assertSame([], $this->names($this->week3));
        $this->assertSame(['Round robin'], array_map(fn ($t) => $t->name, app(Topics::class)->list($this->by, $this->workspace)));
        // Dismissed is dismissed: the reader doesn't suggest them again.
        $this->assertSame(0, $this->suggestions->suggest($this->by, $this->week3, null, ['CPU scheduling', 'Deadlocks']));
        // Acting on one already acted on changes nothing.
        $this->assertSame(0, $this->suggestions->add($this->by, $list[1]->id));
    }

    public function test_another_students_module_and_suggestions_are_not_found(): void
    {
        $this->suggestions->suggest($this->by, $this->week3, null, ['CPU scheduling']);
        $mine = $this->suggestions->list($this->by, $this->week3)[0];
        $bob = $this->principal($this->student());

        foreach ([
            fn () => $this->suggestions->list($bob, $this->week3),
            fn () => $this->suggestions->suggest($bob, $this->week3, null, ['X']),
            fn () => $this->suggestions->addAll($bob, $this->week3),
            fn () => $this->suggestions->add($bob, $mine->id),
            fn () => $this->suggestions->dismiss($bob, $mine->id),
            fn () => $this->suggestions->dismissAll($bob, $this->week3),
            fn () => $this->suggestions->waiting($bob, $this->workspace),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['CPU scheduling'], $this->names($this->week3));
    }

    public function test_deleting_the_module_or_the_course_takes_its_suggestions(): void
    {
        $this->suggestions->suggest($this->by, $this->week3, null, ['CPU scheduling']);
        $this->suggestions->suggest($this->by, $this->week4, null, ['Paging']);

        app(Modules::class)->delete($this->by, $this->week3);
        $this->assertSame(['Paging'], $this->names($this->week4));

        app(Workspaces::class)->delete($this->by, $this->workspace);
        $this->assertSame(0, DB::table('topic_suggestions')->count());
    }
}
