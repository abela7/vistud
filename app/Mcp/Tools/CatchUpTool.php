<?php

namespace App\Mcp\Tools;

use App\Engine\Context\StudentName;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Instructions;
use App\Study\Modules;
use App\Study\QuestionDetails;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;

/**
 * The first call of every new chat (the owner's ask, 2026-10-24): who the student is, what tutors remember, the rules
 * they gave the tutor, the open session, and for each course (or the one named) its last sessions, its assignments in
 * progress, what confuses them and their open questions; so a new chat in any app picks up where the last one
 * stopped. Read only, no session needed. Text the student wrote is fenced as material.
 */
final class CatchUpTool extends McpTool
{
    /** Per course: the last sessions, open questions and assignments shown. */
    private const SESSIONS = 3;

    private const QUESTIONS = 5;

    private const ASSIGNMENTS = 6;

    protected function info(): string
    {
        return 'catch_up';
    }

    public function description(): string
    {
        return 'Call this first in every new chat: who the student is, what tutors remember about them, the rules they gave the tutor (how to teach them), the open study session, and for each course its last sessions (summary and where to pick up), assignments in progress (stage, due date, assignment_id), topics that confuse them and open questions. Give course_id for one course only. Build on it; never start from zero.';
    }

    protected function fields(): array
    {
        return ['course_id' => ['type' => 'string', 'description' => 'Optional. One course\'s id, from courses; left out: every active course.']];
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $c = Container::getInstance();
        $workspaces = $c->make(Workspaces::class);
        $instructions = $c->make(Instructions::class);
        $memories = $c->make(TutorMemories::class);
        $sessions = $c->make(Sessions::class);
        $zone = $sessions->timezone($by);
        $today = CarbonImmutable::now($zone)->toDateString();
        $soon = CarbonImmutable::now($zone)->addDays(14)->toDateString();

        $only = is_string($arguments['course_id'] ?? null) && $arguments['course_id'] !== '' ? $arguments['course_id'] : null;
        $courses = $only !== null ? [$workspaces->find($by, $only)] : $workspaces->list($by);

        $lines = ['# Catch up', '', "Today: {$today}."];
        $name = StudentName::of($by);
        if ($name !== null) {
            $lines[] = "The student: {$name}.";
        }
        $me = $instructions->get($by, 'me');
        if ($me !== '') {
            $lines = [...$lines, '', '## Their rules for every tutor', '<material>', $me, '</material>'];
        }
        $everywhere = $memories->lines($by, null);
        if ($everywhere !== []) {
            $lines = [...$lines, '', '## What tutors remember (all courses)', ...array_map(fn (string $l) => "- {$l}", $everywhere)];
        }
        $open = $sessions->current($by);
        $lines = [...$lines, '', '## Open study session', $open !== null
            ? "session_id {$open->id} in course_id {$open->workspaceId} ({$open->state}). Continue it with that session_id."
            : 'None. Call start_session before teaching or saving.'];

        foreach ($courses as $course) {
            $lines = [...$lines, '', "## Course: {$course->name} (course_id {$course->id})"];
            $lines = [...$lines, ...$this->course($by, $course->id, $today, $soon)];
        }

        return ['text' => implode("\n", $lines), 'type' => $only !== null ? 'course' : null, 'id' => $only];
    }

    /** @return list<string> */
    private function course(Principal $by, string $courseId, string $today, string $soon): array
    {
        $c = Container::getInstance();
        $lines = [];
        $rules = $c->make(Instructions::class)->get($by, "workspace:{$courseId}");
        if ($rules !== '') {
            $lines = [...$lines, 'Their rules for this course:', '<material>', $rules, '</material>'];
        }
        $remembered = array_values(array_diff($c->make(TutorMemories::class)->lines($by, $courseId), $c->make(TutorMemories::class)->lines($by, null)));
        if ($remembered !== []) {
            $lines = [...$lines, 'Remembered for this course:', ...array_map(fn (string $l) => "- {$l}", $remembered)];
        }

        $ended = array_values(array_filter($c->make(Sessions::class)->list($by, $courseId), fn (SessionDetails $s) => ! $s->isOpen()));
        if ($ended !== []) {
            $lines[] = 'How the tutor teaches them (as last set): '.Tutoring::summary($ended[0]->tutoring ?? []).'.';
            $lines[] = 'Last sessions:';
            foreach (array_slice($ended, 0, self::SESSIONS) as $s) {
                $said = $s->summary ?? ($s->checkpoint !== null ? "It stopped at: {$s->checkpoint}" : 'No summary.');
                $lines[] = '- '.substr((string) ($s->endedAt ?? $s->startedAt), 0, 10).': '.$said.($s->summary !== null && $s->checkpoint !== null ? " Pick up: {$s->checkpoint}" : '');
            }
        }

        $modules = array_column(array_map(fn ($m) => ['id' => $m->id, 'title' => $m->title], $c->make(Modules::class)->list($by, $courseId)), 'title', 'id');
        $work = array_values(array_filter($c->make(Activities::class)->list($by, $courseId), fn (ActivityDetails $a) => $a->status !== 'done' && in_array($a->kind, ['assignment', 'project', 'exam', 'problem_set', 'quiz'], true)));
        usort($work, fn (ActivityDetails $a, ActivityDetails $b) => [$a->dueOn ?? '9999', $a->title] <=> [$b->dueOn ?? '9999', $b->title]);
        if ($work !== []) {
            $lines[] = 'Assignments and exams in progress:';
            foreach (array_slice($work, 0, self::ASSIGNMENTS) as $a) {
                $lines[] = "- {$a->title} ({$a->kind}, stage {$a->stage}".($a->dueOn !== null ? ', due '.$a->dueOn.($a->dueOn < $today ? ', overdue' : ($a->dueOn <= $soon ? ', soon' : '')) : '').", assignment_id {$a->id})";
            }
        }

        $confusing = array_values(array_filter($c->make(Topics::class)->list($by, $courseId), fn ($t) => $t->shown() === 'confused'));
        if ($confusing !== []) {
            $lines[] = 'Topics that confuse them: '.implode(', ', array_map(fn ($t) => $t->name.($t->moduleId !== null && isset($modules[$t->moduleId]) ? " ({$modules[$t->moduleId]})" : ''), $confusing)).'.';
        }
        $asked = array_values(array_filter($c->make(Questions::class)->list($by, $courseId), fn (QuestionDetails $q) => $q->open()));
        if ($asked !== []) {
            $lines[] = 'Open questions ('.count($asked).'):';
            foreach (array_slice($asked, 0, self::QUESTIONS) as $q) {
                $lines[] = '- '.$q->text;
            }
        }

        return $lines === [] ? ['Nothing studied here yet.'] : $lines;
    }
}
