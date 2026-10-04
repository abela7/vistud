<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\TopicSheet;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Findings;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The topic sheet's own jobs beyond saying where the student stands: who set the status, key points, removing the topic (docs/specs/vistud-2-blueprint.md §3.5.3, §3.8). */
class TopicSheetScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $this->topic = app(Topics::class)->create($this->by, $this->workspace, 'Deadlocks', $module)->id;
    }

    private function sheet()
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(TopicSheet::class, ['workspaceId' => $this->workspace])->dispatch('topic-sheet-open', topicId: $this->topic);
    }

    public function test_a_status_the_tutor_set_says_so_and_the_students_own_does_not(): void
    {
        app(Topics::class)->mark($this->by, $this->topic, 'confused');
        $this->sheet()->assertSee('Set by the tutor, ')->assertSee('Pick another to change it.');

        app(Topics::class)->report($this->by, $this->topic, 'understood');
        $this->sheet()->assertDontSee('Set by the tutor');
    }

    public function test_key_points_are_added_and_removed_on_the_sheet(): void
    {
        $sheet = $this->sheet()->assertSee('Key points')
            ->call('addPoint')->assertHasErrors('point')
            ->set('point', 'A deadlock needs all four Coffman conditions.')->call('addPoint')
            ->assertSee('Key point added.')->assertSee('A deadlock needs all four Coffman conditions.')->assertSet('point', '');
        $points = app(Findings::class)->byTopic($this->by, $this->workspace)[$this->topic];
        $this->assertSame(['A deadlock needs all four Coffman conditions.'], array_map(fn ($point) => $point->text, $points));
        $this->assertSame('student', $points[0]->author);

        $sheet->call('removePoint', $points[0]->id)->assertSee('Key point removed.')->assertDontSee('A deadlock needs all four');
        $this->assertSame([], app(Findings::class)->byTopic($this->by, $this->workspace)[$this->topic] ?? []);
    }

    public function test_a_key_point_says_where_it_came_from(): void
    {
        $note = app(Notes::class)->create($this->by, 'workspace', $this->workspace, 'Lecture 3');
        app(Findings::class)->add($this->by, $this->topic, ['text' => 'Prevent circular wait.', 'source' => "note:{$note->id}", 'locator' => 'slide 12']);

        $this->sheet()->assertSeeInOrder(['Prevent circular wait.', 'from Lecture 3', 'slide 12']);
    }

    public function test_the_topic_is_removed_after_asking_and_what_was_done_stays(): void
    {
        $sheet = $this->sheet()->assertSee('Remove topic')
            ->call('askRemove')->assertSet('confirmingRemove', true)->assertSee('What you did on it stays in your journal.')
            ->call('keep')->assertSet('confirmingRemove', false);
        $this->assertCount(1, app(Topics::class)->list($this->by, $this->workspace));

        $sheet->call('askRemove')->call('remove')->assertDispatched('topics-changed')->assertDispatched('topic-sheet-dialog-close')->assertSet('topicId', null);
        $this->assertSame([], app(Topics::class)->list($this->by, $this->workspace));
    }

    public function test_the_browser_cannot_change_the_topic_or_the_question_of_removing(): void
    {
        $this->assertThrows(fn () => $this->sheet()->set('topicId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->sheet()->set('confirmingRemove', true), CannotUpdateLockedPropertyException::class);
    }

    public function test_another_students_key_point_cannot_be_removed(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private'])->id;
        $secret = app(Topics::class)->create($this->principal($bob), $theirs, 'Secret')->id;
        $point = app(Findings::class)->add($this->principal($bob), $secret, ['text' => 'Not yours.']);

        try {
            $this->sheet()->call('removePoint', $point->id);
            $this->fail('Expected the key point to be missing.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(NotFound::class, $e->getPrevious() ?? $e);
        }
        $this->assertCount(1, app(Findings::class)->byTopic($this->principal($bob), $theirs)[$secret]);
    }
}
