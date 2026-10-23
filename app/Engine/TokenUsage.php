<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Sessions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How many tokens a student's AI use took (the owner's ask, 2026-10-23): for one chat, and for today, this week, this
 * month and all time, broken down by area (the kind of chat, or the job), by course and by model. Every call's tokens
 * are as the service reported them (in, cached among them, out) with what it cost; a chat's are kept on its messages,
 * a job's on its run, and what deleted chats and courses took on the rows that keep their cost (App\Engine\Spent).
 * Days and weeks are the student's own, in their time zone; a week starts on Monday.
 */
final class TokenUsage
{
    public const PERIODS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'];

    /** What a background job was for, by its kind; another kind is named from its own words. */
    private const JOB_AREAS = [
        'module_guide' => 'Course guide', 'setup_guide' => 'Course guide',
        'read_file' => 'Reading files', 'note_from_file' => 'Reading files', 'profile_course' => 'Reading files',
        'fold' => 'Keeping long chats short', 'wrap_up' => 'Session summaries',
        'transcribe_class' => 'Class recordings', 'transcribe_piece' => 'Class recordings', 'transcribe_notes' => 'Class recordings', 'class_check' => 'Class recordings',
        'cards_from' => 'Flashcards and quizzes', 'choose_topics' => 'Topics', 'answer_from_notes' => 'Answers using your notes',
        'understand_assignment' => 'Assignment help', 'plan_assignment' => 'Assignment help',
        'quick' => 'Quick helper', 'dictation' => 'Voice typing',
        Spent::KIND => 'Deleted chats and courses',
    ];

    public function __construct(private Sessions $sessions, private Workspaces $workspaces) {}

    /** @return array{calls: int, in: int, cached: int, out: int, total: int, cost: int} nothing yet */
    public static function zero(): array
    {
        return ['calls' => 0, 'in' => 0, 'cached' => 0, 'out' => 0, 'total' => 0, 'cost' => 0];
    }

    /**
     * What one chat (an engine thread) took.
     *
     * @return array{calls: int, in: int, cached: int, out: int, total: int, cost: int}
     */
    public function thread(Principal $by, string $threadId): array
    {
        $row = LearnerTables::query(Guard::learner($by), 'engine_messages')->where('thread_id', $threadId)->where('role', 'assistant')
            ->selectRaw('count(*) as calls, coalesce(sum(tokens_in), 0) as tokens_in, coalesce(sum(tokens_cached), 0) as tokens_cached, coalesce(sum(tokens_out), 0) as tokens_out, coalesce(sum(cost_micros), 0) as cost')
            ->first();

        return self::add(self::zero(), $row);
    }

    /**
     * What the chats of one conversation, session, topic or assignment took: the threads whose $column is $id.
     *
     * @param  'id'|'session_id'|'topic_id'|'activity_id'  $column
     * @return array{calls: int, in: int, cached: int, out: int, total: int, cost: int}
     */
    public function chat(Principal $by, string $column, ?string $id): array
    {
        if ($id === null || $id === '' || ! in_array($column, ['id', 'session_id', 'topic_id', 'activity_id'], true)) {
            return self::zero();
        }
        $scope = Guard::learner($by);
        $threads = LearnerTables::query($scope, 'engine_threads')->where($column, $id)->pluck('id')->all();
        if ($threads === []) {
            return self::zero();
        }
        $row = LearnerTables::query($scope, 'engine_messages')->whereIn('thread_id', $threads)->where('role', 'assistant')
            ->selectRaw('count(*) as calls, coalesce(sum(tokens_in), 0) as tokens_in, coalesce(sum(tokens_cached), 0) as tokens_cached, coalesce(sum(tokens_out), 0) as tokens_out, coalesce(sum(cost_micros), 0) as cost')
            ->first();

        return self::add(self::zero(), $row);
    }

    /**
     * The four periods' totals, and for $period the breakdowns by area, by course and by model, largest first.
     *
     * @return array{totals: array<string, array<string, int>>, period: string, areas: array<string, array<string, int>>, courses: array<string, array<string, int>>, models: array<string, array<string, int>>}
     */
    public function report(Principal $by, string $period = 'month'): array
    {
        $period = isset(self::PERIODS[$period]) ? $period : 'month';
        $scope = Guard::learner($by);
        $starts = $this->starts($by);
        $rows = $this->rows($scope);

        $totals = array_fill_keys(array_keys(self::PERIODS), self::zero());
        [$areas, $courses, $models] = [[], [], []];
        $names = [];
        foreach ([...$this->workspaces->list($by), ...$this->workspaces->list($by, true)] as $course) {
            $names[$course->id] = $course->name;
        }
        foreach ($rows as $row) {
            foreach ($starts as $name => $start) {
                if ($start === null || $row['at'] >= $start) {
                    $totals[$name] = self::add($totals[$name], $row);
                }
            }
            if ($starts[$period] !== null && $row['at'] < $starts[$period]) {
                continue;
            }
            $areas[$row['area']] = self::add($areas[$row['area']] ?? self::zero(), $row);
            $course = $row['course'] === null ? 'No course' : ($names[$row['course']] ?? 'A deleted course');
            $courses[$course] = self::add($courses[$course] ?? self::zero(), $row);
            $model = $row['model'] !== '' ? $row['model'] : 'Not recorded';
            $models[$model] = self::add($models[$model] ?? self::zero(), $row);
        }
        $largest = fn (array $a, array $b) => [$b['total'], $b['cost']] <=> [$a['total'], $a['cost']];
        foreach ([&$areas, &$courses, &$models] as &$list) {
            uasort($list, $largest);
        }

        return ['totals' => $totals, 'period' => $period, 'areas' => $areas, 'courses' => $courses, 'models' => $models];
    }

    /**
     * When each period starts, in UTC (null: all time).
     *
     * @return array{today: string, week: string, month: string, all: null}
     */
    private function starts(Principal $by): array
    {
        $now = CarbonImmutable::now($this->sessions->timezone($by));

        return [
            'today' => $now->startOfDay()->utc()->toDateTimeString(),
            'week' => $now->startOfWeek(CarbonImmutable::MONDAY)->utc()->toDateTimeString(),
            'month' => $now->startOfMonth()->utc()->toDateTimeString(),
            'all' => null,
        ];
    }

    /**
     * Every chat's and job's tokens, summed by hour, area, course and model (all time: a few rows an hour, however many
     * calls; by the hour, so a day or week starting at the student's own midnight is told right), each with when.
     *
     * @return list<array{at: string, area: string, course: ?string, model: string, calls: int, tokens_in: int, tokens_cached: int, tokens_out: int, cost: int}>
     */
    private function rows(LearnerScope $scope): array
    {
        $sums = 'count(*) as calls, coalesce(sum(tokens_in), 0) as tokens_in, coalesce(sum(tokens_cached), 0) as tokens_cached, coalesce(sum(tokens_out), 0) as tokens_out, coalesce(sum(cost_micros), 0) as cost';
        $rows = [];

        // Chats: an answer each (the messages the model wrote), by thread, so its kind and course can be told.
        $byThread = LearnerTables::query($scope, 'engine_messages')->where('role', 'assistant')
            ->selectRaw("thread_id, coalesce(model, '') as model, min(created_at) as at, ".$sums)
            ->groupBy('thread_id', 'model', DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H')"))->get();
        $threads = LearnerTables::query($scope, 'engine_threads')->whereIn('id', $byThread->pluck('thread_id')->unique()->values()->all())
            ->get(['id', 'workspace_id', 'session_id', 'topic_id', 'activity_id', 'purpose'])->keyBy('id');
        foreach ($byThread as $row) {
            $thread = $threads[$row->thread_id] ?? null;
            $rows[] = ['at' => (string) $row->at, 'area' => self::chatArea($thread), 'course' => $thread?->workspace_id, 'model' => (string) $row->model] + self::sums($row);
        }

        // Jobs: a run each, by kind.
        $jobs = LearnerTables::query($scope, 'engine_jobs')->where(fn ($q) => $q->where('cost_micros', '>', 0)->orWhere('tokens_in', '>', 0))
            ->selectRaw("kind, workspace_id, coalesce(model, '') as model, min(created_at) as at, ".$sums)
            ->groupBy('kind', 'workspace_id', 'model', DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H')"))->get();
        foreach ($jobs as $row) {
            $rows[] = ['at' => (string) $row->at, 'area' => self::jobArea((string) $row->kind), 'course' => $row->workspace_id, 'model' => (string) $row->model] + self::sums($row);
        }

        return $rows;
    }

    /** What kind of chat a thread is: ViStud AI, an assignment's, a topic's, or a study session's. */
    private static function chatArea(?object $thread): string
    {
        return match (true) {
            $thread === null => 'Chats',
            $thread->purpose === VistudAi::PURPOSE => 'ViStud AI',
            $thread->purpose === 'assignment' || $thread->activity_id !== null => 'Assignment help',
            $thread->topic_id !== null => 'Topic chats',
            $thread->session_id !== null => 'Study sessions',
            default => 'Chats',
        };
    }

    private static function jobArea(string $kind): string
    {
        return self::JOB_AREAS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /** @return array{calls: int, tokens_in: int, tokens_cached: int, tokens_out: int, cost: int} */
    private static function sums(object $row): array
    {
        return ['calls' => (int) $row->calls, 'tokens_in' => (int) $row->tokens_in, 'tokens_cached' => (int) $row->tokens_cached, 'tokens_out' => (int) $row->tokens_out, 'cost' => (int) $row->cost];
    }

    /**
     * @param  array{calls: int, in: int, cached: int, out: int, total: int, cost: int}  $total
     * @param  array<string, mixed>|object|null  $row
     * @return array{calls: int, in: int, cached: int, out: int, total: int, cost: int}
     */
    private static function add(array $total, array|object|null $row): array
    {
        if ($row === null) {
            return $total;
        }
        $row = (array) $row;
        [$in, $out] = [(int) ($row['tokens_in'] ?? 0), (int) ($row['tokens_out'] ?? 0)];

        return [
            'calls' => $total['calls'] + (int) ($row['calls'] ?? 0),
            'in' => $total['in'] + $in,
            'cached' => $total['cached'] + (int) ($row['tokens_cached'] ?? 0),
            'out' => $total['out'] + $out,
            'total' => $total['total'] + $in + $out,
            'cost' => $total['cost'] + (int) ($row['cost'] ?? 0),
        ];
    }

    /** "1.2k", "3.4M": a token count as it reads. */
    public static function short(int $tokens): string
    {
        return match (true) {
            $tokens >= 1_000_000 => rtrim(rtrim(number_format($tokens / 1_000_000, 1), '0'), '.').'M',
            $tokens >= 1_000 => rtrim(rtrim(number_format($tokens / 1_000, 1), '0'), '.').'k',
            default => (string) $tokens,
        };
    }
}
