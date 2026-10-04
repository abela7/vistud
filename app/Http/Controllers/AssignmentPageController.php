<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\PlanItem;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * An assignment on a page of its own, /courses/{workspace}/assignments/{assignment}, with its deadline, where
 * the student is with it and its files; and a new one, /courses/{workspace}/assignments/new (`module` says
 * where it starts). Another student's assignment, or one from another workspace, answers 404 like a missing
 * one. The page itself is App\Livewire\Workspaces\AssignmentPage (the owner's review, 2026-10-02).
 */
class AssignmentPageController
{
    public function show(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, string $workspace, string $assignment): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $found = $activities->find($by, $assignment);
        $found->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.assignment', ['workspace' => $details, 'assignment' => $found, 'module' => null]);
    }

    /** A section's own page: its tasks, its files and its notes. */
    public function section(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, Plans $plans, string $workspace, string $assignment, string $plansection): View
    {
        [$details, $found, $part] = $this->section_($request, $principals, $workspaces, $activities, $plans, $workspace, $assignment, $plansection);

        return view('workspaces.section', ['workspace' => $details, 'assignment' => $found, 'section' => $part]);
    }

    /** A new section of an assignment's plan, on a page of its own. */
    public function createSection(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, string $workspace, string $assignment): View
    {
        [$details, $found] = $this->assignment_($request, $principals, $workspaces, $activities, $workspace, $assignment);

        return view('workspaces.section-form', ['workspace' => $details, 'assignment' => $found, 'section' => null]);
    }

    /** Changing a section: its name, weight, due day and description. */
    public function editSection(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, Plans $plans, string $workspace, string $assignment, string $plansection): View
    {
        [$details, $found, $part] = $this->section_($request, $principals, $workspaces, $activities, $plans, $workspace, $assignment, $plansection);

        return view('workspaces.section-form', ['workspace' => $details, 'assignment' => $found, 'section' => $part]);
    }

    /** @return array{0: WorkspaceDetails, 1: ActivityDetails} the workspace and its assignment, or 404 */
    private function assignment_(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, string $workspace, string $assignment): array
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $found = $activities->find($by, $assignment);
        $found->workspaceId === $details->id || throw new NotFound;

        return [$details, $found];
    }

    /** @return array{0: WorkspaceDetails, 1: ActivityDetails, 2: PlanItem} and the section of its plan, or 404 */
    private function section_(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, Plans $plans, string $workspace, string $assignment, string $plansection): array
    {
        [$details, $found] = $this->assignment_($request, $principals, $workspaces, $activities, $workspace, $assignment);
        $part = $plans->get($principals->fromRequest($request), $found->id)->item($plansection);
        $part !== null && $part->kind === 'part' || throw new NotFound;

        return [$details, $found, $part];
    }

    public function create(Request $request, PrincipalFactory $principals, Workspaces $workspaces, string $workspace): View
    {
        $details = $workspaces->find($principals->fromRequest($request), $workspace);
        $module = $request->query('module');

        return view('workspaces.assignment', ['workspace' => $details, 'assignment' => null, 'module' => is_string($module) && $module !== '' ? $module : null]);
    }
}
