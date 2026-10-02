<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Folders;
use App\Study\PlanMaker;
use App\Study\PlanMember;
use App\Study\Plans;
use App\Study\PlanStarters;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Projects and group work: dates, states, nested steps, milestones, a team and health (the owner's review, 2026-10-03). */
class ProjectPlansTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ActivityDetails $project;

    private Plans $plans;

    private Activities $activities;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Software engineering']);
        $this->activities = app(Activities::class);
        $this->plans = app(Plans::class);
        $this->project = $this->activities->create($this->by, $this->databases->id, ['kind' => 'project', 'title' => 'Library system', 'due_on' => '2026-12-01']);
    }

    public function test_a_project_is_an_assignment_of_its_own_kind_with_its_folder(): void
    {
        $this->assertSame('Project', $this->project->kindLabel());
        $this->assertSame('Library system', app(Folders::class)->find($this->by, $this->project->folderId)->name);
    }

    public function test_steps_can_be_doing_stuck_or_done_and_a_part_is_as_far_as_its_steps(): void
    {
        $build = $this->plans->addPart($this->by, $this->project->id, 'Build');
        $a = $this->plans->addStep($this->by, $this->project->id, 'Database', $build->id);
        $b = $this->plans->addStep($this->by, $this->project->id, 'Screens', $build->id);
        $state = fn () => $this->plans->get($this->by, $this->project->id)->stateOf($this->plans->get($this->by, $this->project->id)->item($build->id));

        $this->assertSame('todo', $state());
        $this->plans->setState($this->by, $a->id, 'doing');
        $this->assertSame(['doing', 'doing'], [$state(), $this->activities->find($this->by, $this->project->id)->status]);
        $this->plans->setState($this->by, $b->id, 'stuck');
        $this->assertSame('stuck', $state());
        $this->plans->setState($this->by, $b->id, 'doing');
        $this->plans->setState($this->by, $a->id, 'done');
        $this->plans->setState($this->by, $b->id, 'done');
        $this->assertSame('done', $state());

        // When it was finished is kept, and cleared if it is opened again.
        $this->assertNotNull($this->plans->get($this->by, $this->project->id)->item($a->id)->doneAt);
        $this->plans->setState($this->by, $a->id, 'todo');
        $this->assertNull($this->plans->get($this->by, $this->project->id)->item($a->id)->doneAt);
        $this->assertThrows(fn () => $this->plans->setState($this->by, $a->id, 'achieved'), Unprocessable::class);
    }

    public function test_steps_nest_three_levels_deep_and_progress_counts_only_the_steps_at_the_end(): void
    {
        $part = $this->plans->addPart($this->by, $this->project->id, 'Build');
        $one = $this->plans->addStep($this->by, $this->project->id, 'Backend', $part->id);
        $two = $this->plans->addStep($this->by, $this->project->id, 'API', $one->id);
        $three = $this->plans->addStep($this->by, $this->project->id, 'Login endpoint', $two->id);
        $this->assertThrows(fn () => $this->plans->addStep($this->by, $this->project->id, 'Too deep', $three->id), Conflict::class);
        $this->plans->addStep($this->by, $this->project->id, 'Books endpoint', $two->id);
        $this->plans->addStep($this->by, $this->project->id, 'Frontend', $part->id);

        $plan = $this->plans->get($this->by, $this->project->id);
        $this->assertSame([0, 3], $plan->counts($part));
        $this->plans->setState($this->by, $three->id, 'done');
        $plan = $this->plans->get($this->by, $this->project->id);
        $this->assertSame([1, 3], [$plan->progress()->done, $plan->progress()->total]);
        $this->assertSame(['doing', 'doing', 'doing'], array_map(fn ($id) => $plan->stateOf($plan->item($id)), [$part->id, $one->id, $two->id]));

        // Deleting a step takes every step under it.
        $this->plans->delete($this->by, $one->id);
        $this->assertSame(['Frontend'], array_map(fn ($i) => $i->title, array_values(array_filter($this->plans->get($this->by, $this->project->id)->items, fn ($i) => $i->kind === 'step'))));
    }

    public function test_an_item_takes_dates_priority_notes_labels_and_a_person_and_only_what_is_given_changes(): void
    {
        $step = $this->plans->addStep($this->by, $this->project->id, 'Write the report');
        $sara = $this->plans->addMember($this->by, $this->project->id, 'Sara Bekele');

        $updated = $this->plans->update($this->by, $step->id, [
            'title' => 'Write the report', 'start_on' => '2026-10-05', 'due_on' => '2026-10-20', 'priority' => 'high',
            'notes' => "Use the template.\nAsk Sara for the data.", 'labels' => ' Writing, urgent ,writing, ', 'member_id' => $sara->id,
        ]);
        $this->assertSame(['2026-10-05', '2026-10-20', 'high', ['Writing', 'urgent'], $sara->id], [$updated->startOn, $updated->dueOn, $updated->priority, $updated->labels, $updated->memberId]);
        $this->assertSame("Use the template.\nAsk Sara for the data.", $updated->notes);

        // Only the title and the due date: everything else stays.
        $again = $this->plans->update($this->by, $step->id, ['title' => 'Write the final report', 'due_on' => '']);
        $this->assertSame(['Write the final report', null, '2026-10-05', 'high', $sara->id], [$again->title, $again->dueOn, $again->startOn, $again->priority, $again->memberId]);

        foreach ([['due_on' => '2026-10-01'], ['start_on' => '2026-02-30'], ['priority' => 'whenever'], ['labels' => 'a,b,c,d,e,f'], ['labels' => str_repeat('x', 25)], ['member_id' => 'nobody'], ['notes' => str_repeat('n', 2001)]] as $bad) {
            $this->assertThrows(fn () => $this->plans->update($this->by, $step->id, $bad + ['title' => 'x', 'start_on' => '2026-10-05']), Unprocessable::class);
        }
    }

    public function test_milestones_are_dates_to_reach_in_date_order_and_overdue_ones_count_as_missed(): void
    {
        $this->plans->addMilestone($this->by, $this->project->id, 'Final hand-in', '2026-12-01');
        $draft = $this->plans->addMilestone($this->by, $this->project->id, 'First draft', '2026-10-01');
        $this->plans->addMilestone($this->by, $this->project->id, 'Meet the tutor');
        $plan = $this->plans->get($this->by, $this->project->id);

        $this->assertSame(['First draft', 'Final hand-in', 'Meet the tutor'], array_map(fn ($m) => $m->title, $plan->milestones()));
        $this->assertSame(['First draft'], array_map(fn ($m) => $m->title, $plan->overdueItems('2026-10-03')));

        $this->plans->setState($this->by, $draft->id, 'achieved');
        $this->assertSame([], $this->plans->get($this->by, $this->project->id)->overdueItems('2026-10-03'));
        // A milestone is not work to tick: it doesn't start the project or count in progress.
        $this->assertSame('todo', $this->activities->find($this->by, $this->project->id)->status);
        $this->assertSame(0, $this->plans->get($this->by, $this->project->id)->progress()->total);
        $this->assertThrows(fn () => $this->plans->addMilestone($this->by, $this->project->id, 'x', 'soon'), Unprocessable::class);
    }

    public function test_a_team_of_names_shares_the_work_and_what_a_part_has_goes_to_its_steps(): void
    {
        $me = $this->plans->addMember($this->by, $this->project->id, 'Ada', me: true);
        $sara = $this->plans->addMember($this->by, $this->project->id, 'Sara');
        $part = $this->plans->addPart($this->by, $this->project->id, 'Frontend');
        $x = $this->plans->addStep($this->by, $this->project->id, 'Screens', $part->id);
        $y = $this->plans->addStep($this->by, $this->project->id, 'Styles', $part->id);
        $z = $this->plans->addStep($this->by, $this->project->id, 'Database');
        $w = $this->plans->addStep($this->by, $this->project->id, 'Tests');
        $this->plans->update($this->by, $part->id, ['title' => 'Frontend', 'member_id' => $sara->id]);
        $this->plans->update($this->by, $y->id, ['title' => 'Styles', 'member_id' => $me->id]);
        $this->plans->update($this->by, $z->id, ['title' => 'Database', 'member_id' => $me->id]);
        $this->plans->setState($this->by, $x->id, 'done');
        $this->plans->setState($this->by, $z->id, 'done');

        $plan = $this->plans->get($this->by, $this->project->id);
        $this->assertSame([$sara->id, $me->id, $me->id, null], array_map(fn ($id) => $plan->memberOf($plan->item($id))?->id, [$x->id, $y->id, $z->id, $w->id]));
        $rows = array_map(fn ($row) => [$row['member']?->name, $row['done'], $row['total']], $plan->workload());
        $this->assertSame([['Ada', 1, 2], ['Sara', 1, 1], [null, 0, 1]], $rows);

        // Only one name is the student's own; a person who leaves gives their work back.
        $this->plans->markMe($this->by, $sara->id, true);
        $this->assertSame(['Sara'], array_map(fn ($m) => $m->name, array_values(array_filter($this->plans->get($this->by, $this->project->id)->members, fn ($m) => $m->me))));
        $this->plans->removeMember($this->by, $me->id);
        $plan = $this->plans->get($this->by, $this->project->id);
        $this->assertNull($plan->item($z->id)->memberId);
        $this->assertSame('SB', (new PlanMember('x', 'Sara Bekele', false, 1))->initials());
        $this->assertSame('Sara Bekele', $this->plans->renameMember($this->by, $sara->id, ' Sara  Bekele ')->name);
        $this->assertThrows(fn () => $this->plans->addMember($this->by, $this->project->id, ''), Unprocessable::class);
    }

    public function test_health_is_worked_out_from_the_pace_stuck_steps_overdue_things_and_missed_milestones(): void
    {
        $project = fn () => $this->activities->find($this->by, $this->project->id);
        $health = fn () => $this->plans->get($this->by, $this->project->id)->health($project());
        $this->assertNull($health());

        $part = $this->plans->addPart($this->by, $this->project->id, 'Build');
        $steps = [];
        foreach (range(1, 4) as $i) {
            $steps[] = $this->plans->addStep($this->by, $this->project->id, "Step {$i}", $part->id);
        }
        $this->assertSame(['on_track', []], [$health()['state'], $health()['reasons']]);

        $this->plans->setState($this->by, $steps[0]->id, 'stuck');
        $this->assertSame(['at_risk', ['1 step is stuck']], [$health()['state'], $health()['reasons']]);
        $this->plans->setState($this->by, $steps[0]->id, 'doing');

        $this->plans->update($this->by, $steps[1]->id, ['title' => 'Step 2', 'due_on' => '2026-10-01']);
        $this->plans->addMilestone($this->by, $this->project->id, 'Draft', '2026-09-30');
        $this->assertSame(['at_risk', ['1 thing is overdue', 'A milestone was missed']], [$health()['state'], $health()['reasons']]);

        $this->plans->update($this->by, $steps[2]->id, ['title' => 'Step 3', 'due_on' => '2026-10-02']);
        $this->assertSame('off_track', $health()['state']);

        Carbon::setTestNow(Carbon::parse('2026-12-05 09:00', 'UTC'));
        $this->assertSame('off_track', $health()['state']);
        $this->assertStringContainsString('The deadline has passed with 4 left', $health()['reasons'][0]);

        $this->activities->setStatus($this->by, $this->project->id, 'done');
        $this->assertNull($health());
    }

    public function test_the_project_starters_and_an_ais_milestones_make_milestones_with_or_without_dates(): void
    {
        $this->plans->applyStarter($this->by, $this->project->id, 'project');
        $this->plans->applyStarter($this->by, $this->project->id, 'group');
        $plan = $this->plans->get($this->by, $this->project->id);
        $this->assertSame(['Proposal agreed', 'Halfway review', 'Final hand-in', 'Roles agreed', 'First draft together', 'Final hand-in'], array_map(fn ($m) => $m->title, $plan->milestones()));
        $this->assertSame([null], array_unique(array_map(fn ($m) => $m->dueOn, $plan->milestones())));
        $this->assertArrayHasKey('group', PlanStarters::ALL);

        $read = PlanMaker::read('<milestone date="2026-11-14">First draft to the tutor</milestone><milestone date="soon">Check-in</milestone><milestone>  </milestone>');
        $this->assertSame([['title' => 'First draft to the tutor', 'due_on' => '2026-11-14'], ['title' => 'Check-in', 'due_on' => null]], $read['milestones']);
        $this->assertSame(2, $this->plans->addAll($this->by, $this->project->id, $read));
    }

    public function test_only_the_owner_can_use_a_team_or_milestones_and_deleting_takes_them_along(): void
    {
        $sara = $this->plans->addMember($this->by, $this->project->id, 'Sara');
        $milestone = $this->plans->addMilestone($this->by, $this->project->id, 'Draft', '2026-11-01');
        $bob = $this->principal($this->student());

        $this->assertThrows(fn () => $this->plans->addMember($bob, $this->project->id, 'Mine'), NotFound::class);
        $this->assertThrows(fn () => $this->plans->removeMember($bob, $sara->id), NotFound::class);
        $this->assertThrows(fn () => $this->plans->markMe($bob, $sara->id, true), NotFound::class);
        $this->assertThrows(fn () => $this->plans->update($bob, $milestone->id, ['title' => 'x']), NotFound::class);

        $this->activities->delete($this->by, $this->project->id);
        $this->assertSame([0, 0], [DB::table('activity_items')->count(), DB::table('activity_members')->count()]);
    }
}
