<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Lookup;
use App\Engine\Tools\Tool;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Memory;
use App\Study\Rollups;
use App\Study\TutorMemories;
use Carbon\CarbonImmutable;

/**
 * Every course of the student's, for ViStud AI: the active ones with where each stands (topics understood, what needs
 * attention, cards due), when each was last studied, and how much is remembered for it; the archived ones by name and
 * term only (their numbers are one course_overview away), so the answer stays short after years of courses.
 */
final class CoursesTool implements Tool
{
    public function __construct(private Courses $courses, private Rollups $rollups, private Memory $memory, private TutorMemories $memories) {}

    public function name(): string
    {
        return 'courses';
    }

    public function description(): string
    {
        return 'Every course: the active ones with how far each has come (topics understood of all, what needs attention, cards due), when it was last studied and how much is remembered for it; with archived true, the archived ones too (name, code, term). Use it first when the student asks about their study as a whole.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'archived' => ['type' => 'boolean', 'description' => 'True to list the archived courses too.'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $scope = Guard::learner($by);
        $last = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->selectRaw('workspace_id, max(started_at) as last, count(*) as n')->groupBy('workspace_id')->get() as $row) {
            $last[(string) $row->workspace_id] = [(string) $row->last, (int) $row->n];
        }
        $remembered = [];
        foreach ($this->memories->all($by) as $memory) {
            if (! $memory->everywhere()) {
                $remembered[$memory->workspaceId] = ($remembered[$memory->workspaceId] ?? 0) + 1;
            }
        }
        $snapshot = null;
        $rows = [];
        foreach ($this->courses->all($by) as $course) {
            $studied = isset($last[$course->id]) ? CarbonImmutable::parse($last[$course->id][0], 'UTC')->setTimezone($context->zone)->toDateString() : null;
            if ($course->archived()) {
                if (($input['archived'] ?? false) === true) {
                    $rows[] = array_filter(['course' => $course->name, 'code' => $course->code, 'term' => $course->term, 'archived' => true, 'last_studied' => $studied]);
                }

                continue;
            }
            $snapshot ??= $this->memory->snapshot($scope);
            $roll = $this->rollups->for($by, $course->id, $snapshot);
            $rows[] = array_filter([
                'course' => $course->name, 'code' => $course->code, 'term' => $course->term,
                'topics' => ['understood' => $roll->done(), 'total' => $roll->total(), 'need_attention' => count($roll->attention), 'not_started' => $roll->notStarted()],
                'cards_due' => $roll->cards['due'] ?: null,
                'sessions' => $last[$course->id][1] ?? 0,
                'last_studied' => $studied,
                'remembered' => $remembered[$course->id] ?? null,
            ], fn ($v) => $v !== null);
        }

        return $rows === [] ? 'No courses yet.' : Lookup::json($rows);
    }
}
