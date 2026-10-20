<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\ClassRecorder;
use App\Livewire\Workspaces\ModuleClasses;
use App\Models\User;
use App\Study\ClassSessions;
use App\Study\Modules as ModuleService;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A module's classes page, and one class on its own page. */
class ClassSessionScreenTest extends TestCase
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

    public function test_a_module_lists_its_classes_and_a_new_one_opens_on_its_own_page(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $classes = app(ClassSessions::class);
        $classes->create($by, $this->biology->id, $module->id, [
            'title' => 'Week 1 lecture',
            'kind' => 'lecture',
            'held_on' => '2026-10-01',
        ]);

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->biology->id, $module->id]))
            ->assertOk()
            ->assertSee(route('workspaces.modules.classes', [$this->biology->id, $module->id]), false)
            ->assertSeeInOrder(['Classes', '1']);

        $this->actingAs($this->ada)->get(route('workspaces.modules.classes', [$this->biology->id, $module->id]))
            ->assertOk()
            ->assertSee('Week 1 lecture')
            ->assertSee('Not recorded yet')
            ->assertSee('Add a class');

        $this->travelTo('2026-10-14 10:00:00');
        Livewire::actingAs($this->ada)
            ->test(ModuleClasses::class, ['workspaceId' => $this->biology->id, 'moduleId' => $module->id])
            ->call('add', 'lab')
            ->assertHasNoErrors()
            ->assertRedirect();

        $added = collect($classes->forModule($by, $this->biology->id, $module->id))->firstWhere('title', 'Lab · 14 Oct 2026');
        $this->assertNotNull($added);
        $this->actingAs($this->ada)->get(route('workspaces.modules.classes.show', [$this->biology->id, $module->id, $added->id]))
            ->assertOk()
            ->assertSee('Lab · 14 Oct 2026')
            ->assertSee('Not recorded yet')
            ->assertSee('Record only if everyone in the room agrees.')
            ->assertSee('I have permission to record');
    }

    public function test_one_tap_on_a_kind_is_all_it_takes_and_an_unknown_kind_makes_nothing(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);

        Livewire::actingAs($this->ada)
            ->test(ModuleClasses::class, ['workspaceId' => $this->biology->id, 'moduleId' => $module->id])
            ->assertSee('Lecture')->assertSee('Tutorial')->assertSee('Lab')->assertSee('Seminar')
            ->call('add', 'party');

        $this->assertSame([], app(ClassSessions::class)->forModule($by, $this->biology->id, $module->id));
    }

    public function test_another_student_or_another_module_cannot_open_the_class(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $other = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Genetics']);
        $class = app(ClassSessions::class)->create($by, $this->biology->id, $module->id, [
            'title' => 'Week 3 lecture',
            'held_on' => '2026-10-14',
        ]);
        $grace = $this->student();

        $this->actingAs($grace)->get(route('workspaces.modules.classes', [$this->biology->id, $module->id]))->assertNotFound();
        $this->actingAs($grace)->get(route('workspaces.modules.classes.show', [$this->biology->id, $module->id, $class->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.modules.classes.show', [$this->biology->id, $other->id, $class->id]))->assertNotFound();
    }

    public function test_permission_unlocks_the_record_button(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $class = app(ClassSessions::class)->create($by, $this->biology->id, $module->id, ['title' => 'Week 3 lecture']);

        Livewire::actingAs($this->ada)
            ->test(ClassRecorder::class, ['workspaceId' => $this->biology->id, 'moduleId' => $module->id, 'classId' => $class->id])
            ->assertSee('I have permission to record')
            ->call('allow')
            ->assertSee('Record')
            ->assertDontSee('I have permission to record');

        $this->assertNotNull(app(ClassSessions::class)->find($by, $class->id)->consentAt);
    }
}
