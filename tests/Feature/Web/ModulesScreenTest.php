<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Models\User;
use App\Study\Folders;
use App\Study\Modules as ModuleService;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A workspace's Modules section (docs/specs/workspaces.md, M2 step 2). */
class ModulesScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->biology = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
    }

    public function test_the_modules_page_and_the_overview_show_the_modules(): void
    {
        $this->module('Cells');
        $this->module('Cell division');

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'modules']))
            ->assertOk()
            ->assertSee('<title>Modules · Biology', false)
            ->assertSeeInOrder(['2 modules', 'New module', 'Cells', 'Cell division'])
            ->assertSee('wire:sort="sortModules"', false);

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->biology->id))
            ->assertSeeInOrder(['Modules', 'Cells', 'Cell division']);
    }

    public function test_adding_and_editing_a_module_through_the_dialog(): void
    {
        $page = $this->page()
            ->call('newModule')
            ->assertDispatched('structure-dialog-open')
            ->assertSee('New module')
            ->call('save')
            ->assertHasErrors('title')
            ->set('title', 'Cells')->set('startsOn', '2026-09-08')
            ->call('save')
            ->assertDispatched('structure-dialog-close')
            ->assertSee('Cells is added.')
            ->assertSet('mode', null);

        $cells = $this->modules()[0];
        $this->assertSame(['Cells', '2026-09-08'], [$cells->title, $cells->startsOn]);

        $page->call('editModule', $cells->id)->assertSet('title', 'Cells')
            ->set('title', 'Cells and organelles')->call('save')
            ->assertSee('Cells and organelles is saved.');
    }

    public function test_reordering_modules_by_dragging_and_from_the_menu(): void
    {
        $cells = $this->module('Cells');
        $division = $this->module('Cell division');
        $photo = $this->module('Photosynthesis');

        $this->page()->call('sortModules', $photo->id, 0);
        $this->assertSame(['Photosynthesis', 'Cells', 'Cell division'], array_map(fn ($m) => $m->title, $this->modules()));

        $this->page()->call('moveModuleBy', $cells->id, -1)->call('moveModuleBy', $division->id, 5);
        $this->assertSame(['Cells', 'Photosynthesis', 'Cell division'], array_map(fn ($m) => $m->title, $this->modules()));
    }

    public function test_folders_are_added_renamed_ordered_moved_and_deleted(): void
    {
        $cells = $this->module('Cells');
        $division = $this->module('Cell division');

        $page = $this->page()
            ->call('newFolder', 'module', $cells->id)->set('name', 'Labs')->call('save')
            ->assertSee('Labs is added.');
        $labs = $this->folders()[0];

        $page->call('newFolder', 'folder', $labs->id)->assertSee('New folder in Labs')->set('name', 'Lab 1')->call('save')
            ->call('newFolder', 'module', $cells->id)->set('name', 'Reading')->call('save')
            ->call('renameFolder', $labs->id)->set('name', 'Lab work')->call('save')
            ->call('moveFolderBy', $labs->id, 1);
        $this->assertSame(['Reading', 'Lab work', 'Lab 1'], array_map(fn ($f) => $f->name, $this->folders()));

        $page->call('moveFolder', $labs->id)
            ->assertSet('destination', "module:{$cells->id}")
            ->assertSee('Move “Lab work”')
            ->set('destination', "module:{$division->id}")
            ->call('save')
            ->assertSee('Lab work is moved.');
        $this->assertSame($division->id, app(Folders::class)->find($this->principal($this->ada), $labs->id)->moduleId);

        $page->call('confirmDelete', 'folder', $labs->id)->call('save')
            ->assertSee('Move or delete what&#039;s inside first.', false)
            ->assertSet('mode', 'delete');
    }

    public function test_a_folder_cannot_be_moved_inside_itself(): void
    {
        $cells = $this->module('Cells');
        $labs = app(Folders::class)->create($this->principal($this->ada), 'module', $cells->id, 'Labs');
        $lab1 = app(Folders::class)->create($this->principal($this->ada), 'folder', $labs->id, 'Lab 1');

        $page = $this->page()->call('moveFolder', $labs->id);
        $options = collect($page->viewData('moveOptions'))->keyBy('value');
        $this->assertTrue($options["folder:{$labs->id}"]['disabled']);
        $this->assertTrue($options["folder:{$lab1->id}"]['disabled']);
        $this->assertFalse($options["module:{$cells->id}"]['disabled']);

        $page->set('destination', "folder:{$lab1->id}")->call('save')->assertSee('A folder can&#039;t go inside itself.', false);
    }

    public function test_the_browser_cannot_change_which_workspace_or_item_the_page_acts_on(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->page()->call('editModule', $this->module('Cells')->id)->set('targetId', 'someone-elses');
    }

    public function test_another_students_workspace_cannot_be_opened_or_changed(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $module = app(ModuleService::class)->create($this->principal($bob), $theirs->id, ['title' => 'Theirs']);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$theirs->id, 'modules']))->assertNotFound();
        $this->page()->call('newFolder', 'module', $module->id)->set('name', 'Mine')->call('save')
            ->assertSee('That no longer exists.');
        $this->assertSame([], app(Folders::class)->tree($this->principal($bob), $theirs->id));
    }

    private function module(string $title)
    {
        return app(ModuleService::class)->create($this->principal($this->ada), $this->biology->id, ['title' => $title]);
    }

    private function modules(): array
    {
        return app(ModuleService::class)->list($this->principal($this->ada), $this->biology->id);
    }

    private function folders(): array
    {
        return app(Folders::class)->tree($this->principal($this->ada), $this->biology->id);
    }

    private function page()
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Contents::class, ['workspaceId' => $this->biology->id, 'view' => 'modules']);
    }
}
