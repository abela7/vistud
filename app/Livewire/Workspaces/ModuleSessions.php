<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Modules;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A module's study sessions on their own page: every study session in this
 * module or on one of its topics, with search, status filtering, sorting,
 * and duration totals.
 */
final class ModuleSessions extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $moduleId;

    public string $search = '';

    public string $filter = 'all';

    public string $sort = 'newest';

    private Sessions $sessions;

    private Topics $topics;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Sessions $sessions, Topics $topics, Modules $modules, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $moduleId): void
    {
        $this->workspaceId = $workspaceId;
        $this->moduleId = $moduleId;
    }

    public function show(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'running', 'ended'], true) ? $filter : 'all';
    }

    public function startSession(): void
    {
        $this->dispatch('study-start', moduleId: $this->moduleId);
    }

    #[On('session-changed')]
    public function sessionChanged(): void
    {
        // Re-renders the component when session state changes.
    }

    public function render(): View
    {
        $by = $this->principal();
        $module = $this->modules->find($by, $this->moduleId);
        $module->workspaceId === $this->workspaceId || throw new NotFound;

        $topics = $this->topics->list($by, $this->workspaceId);
        $topicNames = collect($topics)->pluck('name', 'id')->all();
        $inModule = [];
        foreach ($topics as $topic) {
            $inModule[$topic->id] = $topic->moduleId === $this->moduleId;
        }

        $all = array_values(array_filter(
            $this->sessions->list($by, $this->workspaceId, 100),
            fn ($s) => $s->moduleId === $this->moduleId || ($s->moduleId === null && ($inModule[$s->topicId] ?? false)),
        ));

        $totalStudySeconds = array_sum(array_column($all, 'studySeconds'));
        $runningCount = count(array_filter($all, fn ($s) => $s->isOpen()));
        $endedCount = count(array_filter($all, fn ($s) => ! $s->isOpen()));

        $counts = [
            'all' => count($all),
            'running' => $runningCount,
            'ended' => $endedCount,
        ];

        $filtered = match ($this->filter) {
            'running' => array_filter($all, fn ($s) => $s->isOpen()),
            'ended' => array_filter($all, fn ($s) => ! $s->isOpen()),
            default => $all,
        };

        $search = trim(mb_strtolower($this->search));
        if ($search !== '') {
            $filtered = array_filter($filtered, function ($s) use ($search, $topicNames) {
                $name = mb_strtolower($s->topicId !== null ? ($topicNames[$s->topicId] ?? '') : 'study session');

                return str_contains($name, $search) || str_contains(mb_strtolower($s->summary ?? ''), $search);
            });
        }

        $shown = array_values($filtered);
        match ($this->sort) {
            'oldest' => usort($shown, fn ($a, $b) => $a->startedAt <=> $b->startedAt),
            'longest' => usort($shown, fn ($a, $b) => $b->studySeconds <=> $a->studySeconds),
            default => usort($shown, fn ($a, $b) => $b->startedAt <=> $a->startedAt),
        };

        return view('livewire.workspaces.module-sessions', [
            'module' => $module,
            'counts' => $counts,
            'shown' => $shown,
            'topicNames' => $topicNames,
            'totalDuration' => SessionDetails::duration($totalStudySeconds),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
