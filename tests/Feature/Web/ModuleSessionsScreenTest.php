<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\ModuleSessions;
use App\Models\User;
use App\Study\Modules as ModuleService;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A module's dedicated study sessions page: /workspaces/{workspace}/modules/{module}/sessions */
class ModuleSessionsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->biology = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
    }

    public function test_a_module_page_links_to_its_study_sessions_page(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $topic = app(Topics::class)->create($by, $this->biology->id, 'Mitochondria', $module->id);

        $session = app(Sessions::class)->start($by, $this->biology->id, $topic->id, $module->id);
        $this->travel(30)->minutes();
        app(Sessions::class)->end($by, $session->id);

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->biology->id, $module->id]))
            ->assertOk()
            ->assertSee(route('workspaces.modules.sessions', [$this->biology->id, $module->id]), false)
            ->assertSeeInOrder(['Study sessions', '1', 'session']);
    }

    public function test_the_study_sessions_page_shows_sessions_totals_filters_searches_and_sorts(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $mitochondria = app(Topics::class)->create($by, $this->biology->id, 'Mitochondria', $module->id);
        $ribosomes = app(Topics::class)->create($by, $this->biology->id, 'Ribosomes', $module->id);

        // Session 1: Mitochondria (ended, 25 min)
        $s1 = app(Sessions::class)->start($by, $this->biology->id, $mitochondria->id, $module->id);
        $this->travel(25)->minutes();
        app(Sessions::class)->end($by, $s1->id);

        // Session 2: Ribosomes (ended, 20 min)
        $this->travel(10)->minutes();
        $s2 = app(Sessions::class)->start($by, $this->biology->id, $ribosomes->id, $module->id);
        $this->travel(20)->minutes();
        app(Sessions::class)->end($by, $s2->id);

        // Session 3: Open session in module
        $this->travel(5)->minutes();
        $s3 = app(Sessions::class)->start($by, $this->biology->id, null, $module->id);

        // Page load
        $this->actingAs($this->ada)->get(route('workspaces.modules.sessions', [$this->biology->id, $module->id]))
            ->assertOk()
            ->assertSee('<title>Study sessions · Cells · Biology', false)
            ->assertSeeInOrder(['Modules', 'Cells', 'Study sessions'])
            ->assertSee(route('workspaces.modules.show', [$this->biology->id, $module->id]), false);

        // Component interaction
        Livewire::actingAs($this->ada)
            ->test(ModuleSessions::class, ['workspaceId' => $this->biology->id, 'moduleId' => $module->id])
            ->assertSee('3 sessions · 45 min studied')
            ->assertSeeInOrder(['Study session', 'Ribosomes', 'Mitochondria'])
            ->assertSee('Studying now')
            // Filter by ended
            ->call('show', 'ended')
            ->assertSee('Ribosomes')
            ->assertSee('Mitochondria')
            ->assertDontSee('Studying now')
            // Filter by running
            ->call('show', 'running')
            ->assertSee('Studying now')
            ->assertDontSee('Mitochondria')
            // Reset filter & search
            ->call('show', 'all')
            ->set('search', 'Ribosomes')
            ->assertSee('Ribosomes')
            ->assertDontSee('Mitochondria')
            // Search with no results
            ->set('search', 'Photosynthesis')
            ->assertSee('No study session matches.')
            // Sort by longest
            ->set('search', '')
            ->set('sort', 'longest')
            ->assertSeeInOrder(['Mitochondria', 'Ribosomes'])
            // Dispatch start session
            ->call('startSession')
            ->assertDispatched('study-start', moduleId: $module->id);
    }

    public function test_empty_state_when_module_has_no_sessions(): void
    {
        $by = $this->principal($this->ada);
        $module = app(ModuleService::class)->create($by, $this->biology->id, ['title' => 'Genetics']);

        $this->actingAs($this->ada)->get(route('workspaces.modules.sessions', [$this->biology->id, $module->id]))
            ->assertOk()
            ->assertSee('No study sessions yet')
            ->assertSee('Start studying');
    }

    public function test_another_students_module_sessions_page_is_404(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Theirs']);
        $module = app(ModuleService::class)->create($this->principal($bob), $theirs->id, ['title' => 'Secret']);

        $this->actingAs($this->ada)->get(route('workspaces.modules.sessions', [$theirs->id, $module->id]))->assertNotFound();
    }
}
