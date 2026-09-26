<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Form;
use App\Livewire\Workspaces\Index;
use App\Models\User;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** My workspaces and a workspace's pages (docs/specs/workspaces.md, M2 step 1). */
class WorkspaceScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student(['name' => 'Ada Lovelace']);
    }

    public function test_home_is_my_workspaces_with_a_first_workspace_prompt(): void
    {
        $this->actingAs($this->ada)->get('/')
            ->assertOk()
            ->assertSee('<title>My workspaces', false)
            ->assertSeeInOrder(['Welcome back, Ada', 'My workspaces', 'Create your first workspace'])
            ->assertSee('id="workspace-form"', false);
    }

    public function test_home_lists_workspaces_in_the_sidebar_and_as_cards_and_keeps_archived_ones_apart(): void
    {
        $biology = $this->create('Biology', ['code' => 'BIO101', 'term' => 'Autumn 2026', 'colour' => 'green', 'icon' => 'microscope']);
        $old = $this->create('Old subject');
        app(Workspaces::class)->archive($this->principal($this->ada), $old->id);

        $this->actingAs($this->ada)->get('/')
            ->assertSeeInOrder(['All workspaces', 'Journal', 'Workspaces', 'Biology'])
            ->assertSee('ws-colour-green', false)
            ->assertSee('BIO101 · Autumn 2026')
            ->assertSee(route('workspaces.show', $biology->id), false)
            ->assertSeeInOrder(['Archived (1)', 'Old subject', 'Restore']);
    }

    public function test_a_workspace_page_has_its_switcher_sections_and_tab_bar(): void
    {
        $biology = $this->create('Biology', ['code' => 'BIO101']);
        $this->create('Mathematics');

        $this->actingAs($this->ada)->get(route('workspaces.show', $biology->id))
            ->assertOk()
            ->assertSee('<title>Biology', false)
            ->assertSee('id="ws-menu-sidebar"', false)
            ->assertSee('id="ws-menu-drawer"', false)
            ->assertSeeInOrder(['Biology', 'Mathematics', 'All workspaces', 'New workspace'])
            ->assertSeeInOrder(['Overview', 'Modules', 'Notes &amp; files', 'Calendar', 'Progress'], false)
            ->assertSee('class="app-tabbar"', false)
            ->assertSee('BIO101');

        foreach (['notes', 'calendar', 'progress'] as $section) {
            $this->actingAs($this->ada)->get(route('workspaces.show', [$biology->id, $section]))->assertOk()->assertSee('is coming next');
        }
        $this->actingAs($this->ada)->get("/workspaces/{$biology->id}/nonsense")->assertNotFound();
    }

    public function test_another_students_workspace_is_exactly_as_missing_as_one_that_does_not_exist(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);

        $response = $this->actingAs($this->ada)->get(route('workspaces.show', $theirs->id));
        $missing = $this->actingAs($this->ada)->get(route('workspaces.show', 'no-such-id'));

        $response->assertNotFound()->assertDontSee('Private');
        $this->assertSame($missing->getContent(), $response->getContent());
    }

    public function test_an_account_without_workspaces_keeps_the_admin_home_and_cannot_open_one(): void
    {
        $adminOnly = $this->admin(student: false, attributes: ['name' => 'Grace Hopper']);

        $this->actingAs($adminOnly)->get('/')->assertOk()->assertSee('Welcome back, Grace')->assertDontSee('My workspaces');
        $this->actingAs($adminOnly)->get(route('workspaces.show', 'anything'))->assertForbidden();
    }

    public function test_creating_through_the_form_opens_the_new_workspace(): void
    {
        $this->form()
            ->set('name', 'Biology')->set('colour', 'green')->set('icon', 'microscope')->set('code', 'BIO101')
            ->call('save')
            ->assertRedirect(route('workspaces.show', app(Workspaces::class)->list($this->principal($this->ada))[0]->id));
    }

    public function test_the_form_shows_the_services_refusals_on_their_fields(): void
    {
        $this->form()
            ->set('name', '')->set('startsOn', '2026-10-01')->set('endsOn', '2026-09-01')
            ->call('save')
            ->assertHasErrors(['name', 'endsOn'])
            ->assertSee('Give the workspace a name.')
            ->assertSee('The end date is before the start date.')
            ->assertNoRedirect();
    }

    public function test_editing_archiving_and_restoring_through_the_form(): void
    {
        $biology = $this->create('Biology');

        $this->form($biology->id)
            ->assertSet('name', 'Biology')
            ->set('name', 'Human biology')->set('colour', 'teal')
            ->call('save')
            ->assertRedirect(route('workspaces.show', $biology->id));
        $this->assertSame(['Human biology', 'teal'], [$this->find($biology->id)->name, $this->find($biology->id)->colour]);

        $this->form($biology->id)->call('archive')->assertRedirect(route('home'));
        $this->assertTrue($this->find($biology->id)->archived());
        $this->assertStringContainsString('Human biology is archived.', session('workspace-notice'));

        $this->form($biology->id)->assertSee('Restore workspace')->call('restore')->assertRedirect(route('workspaces.show', $biology->id));
        $this->assertFalse($this->find($biology->id)->archived());
    }

    public function test_restoring_from_my_workspaces(): void
    {
        $biology = $this->create('Biology');
        app(Workspaces::class)->archive($this->principal($this->ada), $biology->id);

        $this->livewire(Index::class)
            ->call('restore', $biology->id)
            ->assertSee('Biology is back in your workspaces.');
        $this->assertFalse($this->find($biology->id)->archived());
    }

    public function test_the_browser_cannot_change_which_workspace_the_form_edits(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->form($this->create('Biology')->id)->set('workspaceId', $this->create('Other')->id);
    }

    private function create(string $name, array $input = [])
    {
        return app(Workspaces::class)->create($this->principal($this->ada), ['name' => $name] + $input);
    }

    private function find(string $id)
    {
        return app(Workspaces::class)->find($this->principal($this->ada), $id);
    }

    private function form(?string $workspaceId = null)
    {
        return $this->livewire(Form::class, ['workspaceId' => $workspaceId]);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, $params);
    }
}
