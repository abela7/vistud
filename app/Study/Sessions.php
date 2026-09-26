<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Study sessions and their clock (docs/specs/study-memory.md §4). A
 * student has at most one open session. Time is counted in segments:
 * study while running, a break while on a break, nothing while paused.
 *
 * The clock is honest by itself, and the student can correct it:
 * - Running with no activity for IDLE_MINUTES pauses the session at the
 *   last activity ("away"); the student can count that time back.
 * - A break longer than BREAK_MINUTES ends there, and the session pauses.
 * - A session with no activity for AUTO_END_HOURS ends at its last activity.
 * These rules run whenever a session is read or changed, and on a schedule.
 *
 * A session is also a `session` record in the journal, and evidence written
 * while it is open carries its id, which the mastery rules count
 * (ADR 0002 §7). The student's own stream only.
 */
final class Sessions
{
    public const IDLE_MINUTES = 30;

    public const BREAK_MINUTES = 60;

    public const AUTO_END_HOURS = 12;

    public const MAX_LOG_MINUTES = 12 * 60;

    public function __construct(private Memory $memory) {}

    /** The student's open session, in any workspace, or null. */
    public function current(Principal $by): ?SessionDetails
    {
        $scope = Guard::learner($by);
        $row = $this->openRow($scope);
        if ($row === null) {
            return null;
        }
        $row = $this->settled($scope, $by, $row->id);

        return $row->state === 'ended' ? null : $this->details($scope, $row);
    }

    public function find(Principal $by, string $id): SessionDetails
    {
        $scope = Guard::learner($by);
        $row = $this->settled($scope, $by, $id);

        return $this->details($scope, $row, withSegments: true);
    }

    /** @return list<SessionDetails> the workspace's sessions, newest first */
    public function list(Principal $by, string $workspaceId, int $limit = 20): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $open = $this->openRow($scope);
        if ($open !== null && $open->workspace_id === $workspaceId) {
            $this->settled($scope, $by, $open->id);
        }

        return LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($row) => $this->details($scope, $row))->all();
    }

    /**
     * Study time in a workspace: since $since (the start of the week, say),
     * and in all. A session counts where it started.
     *
     * @return array{since: int, all: int, sessions: int}
     */
    public function totals(Principal $by, string $workspaceId, CarbonImmutable $since): array
    {
        $totals = ['since' => 0, 'all' => 0, 'sessions' => 0];
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)->get();
        foreach ($rows as $row) {
            $seconds = $row->state === 'ended' ? (int) $row->study_seconds : $this->details($scope, $row)->studySeconds;
            $totals['all'] += $seconds;
            $totals['sessions']++;
            if (CarbonImmutable::parse($row->started_at, 'UTC')->greaterThanOrEqualTo($since)) {
                $totals['since'] += $seconds;
            }
        }

        return $totals;
    }

    /** Starts studying in a workspace, on a topic and in a module if given. Another open session is a conflict. */
    public function start(Principal $by, string $workspaceId, ?string $topicId = null, ?string $moduleId = null): SessionDetails
    {
        $scope = Guard::learner($by);
        $open = $this->openRow($scope);
        if ($open !== null && $this->settled($scope, $by, $open->id)->state !== 'ended') {
            throw new Conflict('session_open', 'Another session is still open. End it first.', ['session' => $open->id, 'workspace' => $open->workspace_id]);
        }
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $topicId, $moduleId, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            [$topicId, $moduleId] = $this->place($scope, $workspaceId, $topicId, $moduleId);
            $now = self::now();
            LearnerTables::insert($scope, 'study_sessions', [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'topic_id' => $topicId,
                'state' => 'running', 'started_at' => $now, 'last_activity_at' => $now, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->openSegment($scope, $id, 'study', $now);
            $this->record($scope, $by, $this->lock($scope, $id));
        });

        return $this->find($by, $id);
    }

    /** Stops the clock without a break: nothing is counted until the student resumes. */
    public function pause(Principal $by, string $id): void
    {
        $this->change($by, $id, ['running', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            $this->closeOpen($scope, $row, $now, 'pause');

            return ['state' => 'paused', 'paused_by' => 'student'];
        });
    }

    /** A break: its time is counted apart from study time. */
    public function takeBreak(Principal $by, string $id): void
    {
        $this->change($by, $id, ['running', 'paused'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            $this->closeOpen($scope, $row, $now, 'break');
            $this->openSegment($scope, $row->id, 'break', $now);

            return ['state' => 'break', 'paused_by' => null];
        });
    }

    /** Back to studying, after a pause or a break. */
    public function resume(Principal $by, string $id): void
    {
        $this->change($by, $id, ['paused', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            $this->closeOpen($scope, $row, $now, 'resume');
            $this->openSegment($scope, $row->id, 'study', $now);

            return ['state' => 'running', 'paused_by' => null];
        });
    }

    /**
     * The session paused itself while the student was away, but they were
     * studying (reading on paper, say): the time since the pause counts, and
     * the clock runs on. Their word wins.
     */
    public function countAway(Principal $by, string $id): void
    {
        $this->change($by, $id, ['paused'], function (LearnerScope $scope, object $row) {
            $segment = LearnerTables::query($scope, 'session_segments')->where('session_id', $row->id)
                ->orderByDesc('started_at')->orderByDesc('id')->first();
            if ($row->paused_by !== 'away' || $segment === null || $segment->ended_by !== 'away') {
                throw new Conflict('nothing_to_count', 'This pause wasn\'t automatic.');
            }
            $seconds = self::seconds($segment->started_at, $segment->ended_at);
            LearnerTables::query($scope, 'session_segments')->where('id', $segment->id)->update(['ended_at' => null, 'ended_by' => null]);
            LearnerTables::query($scope, 'study_sessions')->where('id', $row->id)->update(['study_seconds' => max(0, $row->study_seconds - $seconds)]);

            return ['state' => 'running', 'paused_by' => null];
        });
    }

    /** Ends the session; its totals are kept on the row and in the journal. */
    public function end(Principal $by, string $id): SessionDetails
    {
        $this->change($by, $id, ['running', 'paused', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            $this->closeOpen($scope, $row, $now, 'end');

            return ['state' => 'ended', 'paused_by' => null, 'ended_at' => $now];
        }, journal: true);

        return $this->find($by, $id);
    }

    /** Activity while the clock runs (the page is in use), so an idle pause doesn't happen. */
    public function touch(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $row = $this->settled($scope, $by, $id);
        if ($row->state === 'running') {
            LearnerTables::query($scope, 'study_sessions')->where('id', $id)->where('state', 'running')
                ->update(['last_activity_at' => self::now(), 'updated_at' => self::now()]);
        }
    }

    /**
     * Time studied without the clock, logged afterwards: a date and a start
     * time in the student's time zone, and how long.
     *
     * @param  array{date?: mixed, time?: mixed, minutes?: mixed, topic_id?: mixed}  $input
     */
    public function log(Principal $by, string $workspaceId, array $input, DateTimeZone|string $timezone): SessionDetails
    {
        $scope = Guard::learner($by);
        $zone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;
        $date = is_string($input['date'] ?? null) ? $input['date'] : '';
        $time = is_string($input['time'] ?? null) ? $input['time'] : '';
        $minutes = filter_var($input['minutes'] ?? null, FILTER_VALIDATE_INT);
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $zone);
        $valid = $start !== false && $start->format('Y-m-d H:i') === "{$date} {$time}";
        $start = $valid ? $start->utc() : null;

        Input::refuse(array_filter([
            'date' => $valid ? null : 'Enter the date and time you started.',
            'minutes' => $minutes === false || $minutes < 1 || $minutes > self::MAX_LOG_MINUTES ? 'Enter the minutes, from 1 to '.self::MAX_LOG_MINUTES.'.' : null,
        ]));
        $end = $start->addMinutes($minutes);
        Input::refuse($end->greaterThan(CarbonImmutable::now('UTC')) ? ['date' => 'That time hasn\'t happened yet.'] : []);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $input, $start, $end, $minutes, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            [$topicId] = $this->place($scope, $workspaceId, is_string($input['topic_id'] ?? null) ? $input['topic_id'] : null, null);
            LearnerTables::insert($scope, 'study_sessions', [
                'id' => $id, 'workspace_id' => $workspaceId, 'topic_id' => $topicId, 'state' => 'ended', 'manual' => true,
                'started_at' => $start, 'ended_at' => $end, 'last_activity_at' => $end,
                'study_seconds' => $minutes * 60, 'revision' => 1, 'created_at' => self::now(), 'updated_at' => self::now(),
            ]);
            LearnerTables::insert($scope, 'session_segments', [
                'id' => Ids::new(), 'session_id' => $id, 'kind' => 'study', 'started_at' => $start, 'ended_at' => $end, 'ended_by' => 'log',
            ]);
            $this->record($scope, $by, $this->lock($scope, $id));
        });

        return $this->find($by, $id);
    }

    /** Deletes a session and its time, open or ended (one started by mistake, say). The journal keeps a deleted revision. */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->lock($scope, $id);
            LearnerTables::query($scope, 'session_segments')->where('session_id', $id)->delete();
            LearnerTables::query($scope, 'study_sessions')->where('id', $id)->delete();
            $row->revision++;
            $this->record($scope, $by, $row, deleted: true);
        });
    }

    /**
     * Applies the idle, long-break and auto-end rules to every open session
     * (the scheduler's job; reads apply them too).
     *
     * @return int how many sessions changed
     */
    public function settleAll(LearnerScope $scope, Principal $by): int
    {
        $changed = 0;
        foreach (LearnerTables::query($scope, 'study_sessions')->where('state', '!=', 'ended')->get(['id', 'state', 'paused_by']) as $before) {
            $after = $this->settled($scope, $by, $before->id);
            $changed += [$before->state, $before->paused_by] === [$after->state, $after->paused_by] ? 0 : 1;
        }

        return $changed;
    }

    /** The student's time zone, for their days and weeks (the learner's setting). */
    public function timezone(Principal $by): string
    {
        $zone = DB::table('learners')->where('id', Guard::learner($by)->learnerId)->value('timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /** The open session in a workspace, for stamping journal entries; no rules applied. */
    public static function openIn(LearnerScope $scope, string $workspaceId): ?string
    {
        $id = LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)->where('state', '!=', 'ended')->value('id');

        return $id === null ? null : (string) $id;
    }

    // ---------- The clock ----------

    /**
     * A change of state: locks the row, applies the rules first, checks the
     * state allows it, then saves what $apply returns.
     *
     * @param  list<string>  $from
     */
    private function change(Principal $by, string $id, array $from, callable $apply, bool $journal = false): void
    {
        $scope = Guard::learner($by);
        $this->settled($scope, $by, $id);

        DB::transaction(function () use ($scope, $by, $id, $from, $apply, $journal) {
            $row = $this->lock($scope, $id);
            if (! in_array($row->state, $from, true)) {
                throw new Conflict('session_state', match ($row->state) {
                    'ended' => 'This session has ended.',
                    'running' => 'The session is already running.',
                    'paused' => 'The session is paused.',
                    default => 'The session is on a break.',
                });
            }
            $now = self::now();
            $fields = $apply($scope, $row, $now);
            LearnerTables::query($scope, 'study_sessions')->where('id', $id)->update($fields + [
                'last_activity_at' => $now, 'updated_at' => $now, 'revision' => $journal ? $row->revision + 1 : $row->revision,
            ]);
            if ($journal) {
                $this->record($scope, $by, $this->lock($scope, $id));
            }
        });
    }

    /** The row after the idle, long-break and auto-end rules. */
    private function settled(LearnerScope $scope, Principal $by, string $id): object
    {
        $row = LearnerTables::query($scope, 'study_sessions')->where('id', $id)->first() ?? throw new NotFound;
        if ($row->state === 'ended' || ! $this->due($scope, $row, self::now())) {
            return $row;
        }

        return DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->lock($scope, $id);
            $now = self::now();
            $last = CarbonImmutable::parse($row->last_activity_at, 'UTC');
            $fields = [];

            if ($row->state === 'running' && $now->greaterThan($last->addMinutes(self::IDLE_MINUTES))) {
                $this->closeOpen($scope, $row, $last, 'away');
                $fields = ['state' => 'paused', 'paused_by' => 'away'];
            }
            if ($row->state === 'break') {
                $open = $this->openSegmentRow($scope, $id);
                $limit = $open === null ? null : CarbonImmutable::parse($open->started_at, 'UTC')->addMinutes(self::BREAK_MINUTES);
                if ($limit !== null && $now->greaterThan($limit)) {
                    $this->closeOpen($scope, $row, $limit, 'long_break');
                    $fields = ['state' => 'paused', 'paused_by' => 'long_break'];
                }
            }
            $ended = $now->greaterThan($last->addHours(self::AUTO_END_HOURS));
            if ($ended) {
                $this->closeOpen($scope, $row, $last, 'end');
                $latest = LearnerTables::query($scope, 'session_segments')->where('session_id', $id)->max('ended_at');
                $fields = ['state' => 'ended', 'paused_by' => null, 'ended_at' => max((string) $latest, (string) $row->started_at), 'revision' => $row->revision + 1];
            }
            if ($fields !== []) {
                LearnerTables::query($scope, 'study_sessions')->where('id', $id)->update($fields + ['updated_at' => $now]);
            }
            if ($ended) {
                $this->record($scope, $by, $this->lock($scope, $id));
            }

            return $this->lock($scope, $id);
        });
    }

    /** Whether any rule applies now (checked without a lock first). */
    private function due(LearnerScope $scope, object $row, CarbonImmutable $now): bool
    {
        $last = CarbonImmutable::parse($row->last_activity_at, 'UTC');
        if ($now->greaterThan($last->addHours(self::AUTO_END_HOURS))) {
            return true;
        }
        if ($row->state === 'running') {
            return $now->greaterThan($last->addMinutes(self::IDLE_MINUTES));
        }
        if ($row->state === 'break') {
            $open = $this->openSegmentRow($scope, $row->id);

            return $open !== null && $now->greaterThan(CarbonImmutable::parse($open->started_at, 'UTC')->addMinutes(self::BREAK_MINUTES));
        }

        return false;
    }

    private function openSegment(LearnerScope $scope, string $sessionId, string $kind, CarbonImmutable $at): void
    {
        LearnerTables::insert($scope, 'session_segments', ['id' => Ids::new(), 'session_id' => $sessionId, 'kind' => $kind, 'started_at' => $at]);
    }

    private function openSegmentRow(LearnerScope $scope, string $sessionId): ?object
    {
        return LearnerTables::query($scope, 'session_segments')->where('session_id', $sessionId)->whereNull('ended_at')->first();
    }

    /** Closes the open segment at $at (never before it began) and adds its time to the session's totals. */
    private function closeOpen(LearnerScope $scope, object $row, CarbonImmutable $at, string $reason): void
    {
        $open = $this->openSegmentRow($scope, $row->id);
        if ($open === null) {
            return;
        }
        $at = max($at, CarbonImmutable::parse($open->started_at, 'UTC'));
        LearnerTables::query($scope, 'session_segments')->where('id', $open->id)->update(['ended_at' => $at, 'ended_by' => $reason]);
        $column = $open->kind === 'break' ? 'break_seconds' : 'study_seconds';
        LearnerTables::query($scope, 'study_sessions')->where('id', $row->id)->increment($column, self::seconds($open->started_at, $at));
    }

    // ---------- Helpers ----------

    private function openRow(LearnerScope $scope): ?object
    {
        return LearnerTables::query($scope, 'study_sessions')->where('state', '!=', 'ended')->orderByDesc('started_at')->first();
    }

    private function lock(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'study_sessions')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFound;
    }

    /** @return array{0: ?string, 1: ?string} the topic and module, checked to be the workspace's; a topic's module when none is given */
    private function place(LearnerScope $scope, string $workspaceId, ?string $topicId, ?string $moduleId): array
    {
        $topic = null;
        if ($topicId !== null && $topicId !== '') {
            $topic = LearnerTables::query($scope, 'topics')->where('id', $topicId)->where('workspace_id', $workspaceId)->whereNull('retired_at')->first() ?? throw new NotFound;
        }
        if ($moduleId !== null && $moduleId !== '') {
            $moduleId = (LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->first() ?? throw new NotFound)->id;
        } else {
            $moduleId = $topic?->module_id;
        }

        return [$topic?->id, $moduleId];
    }

    /** A new revision of the session's journal record. */
    private function record(LearnerScope $scope, Principal $by, object $row, bool $deleted = false): void
    {
        $iso = fn (?string $at) => $at === null ? null : CarbonImmutable::parse($at, 'UTC')->format('Y-m-d\TH:i:s.up');
        $this->memory->append($scope, [[
            'id' => Ids::new(),
            'kind' => 'record',
            'actor' => Memory::actor($scope, $by),
            'occurred_at' => Memory::now(),
            'session' => $row->id,
            'body' => array_filter([
                'record_type' => 'session',
                'record_id' => $row->id,
                'revision' => (int) $row->revision,
                'channel' => 'web',
                'client' => $row->manual ? 'logged' : 'vistud',
                'workspace' => $row->workspace_id,
                'module' => $row->module_id,
                'topic' => $row->topic_id,
                'started_at' => $iso($row->started_at),
                'ended_at' => $iso($row->ended_at),
                'study_seconds' => $row->state === 'ended' ? (int) $row->study_seconds : null,
                'break_seconds' => $row->state === 'ended' ? (int) $row->break_seconds : null,
                'status' => $deleted ? 'deleted' : ($row->state === 'ended' ? 'ended' : 'open'),
            ], fn ($value) => $value !== null),
        ]]);
    }

    private function details(LearnerScope $scope, object $row, bool $withSegments = false): SessionDetails
    {
        [$study, $break] = [(int) $row->study_seconds, (int) $row->break_seconds];
        $openSince = null;
        $openKind = null;
        if ($row->state !== 'ended') {
            $open = $this->openSegmentRow($scope, $row->id);
            if ($open !== null) {
                $openSince = CarbonImmutable::parse($open->started_at, 'UTC')->format('Y-m-d\TH:i:s.up');
                $openKind = $open->kind;
                $live = self::seconds($open->started_at, self::now());
                $open->kind === 'break' ? $break += $live : $study += $live;
            }
        }
        $segments = [];
        if ($withSegments) {
            $segments = LearnerTables::query($scope, 'session_segments')->where('session_id', $row->id)->orderBy('started_at')->orderBy('id')->get()
                ->map(fn ($s) => new SessionSegment($s->kind, self::iso($s->started_at), $s->ended_at === null ? null : self::iso($s->ended_at), $s->ended_by))->all();
        }

        return new SessionDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->topic_id, $row->state, $row->paused_by, (bool) $row->manual,
            self::iso($row->started_at), $row->ended_at === null ? null : self::iso($row->ended_at), self::iso($row->last_activity_at),
            $study, $break, $openKind, $openSince, $segments,
        );
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private static function iso(string $at): string
    {
        return CarbonImmutable::parse($at, 'UTC')->format('Y-m-d\TH:i:s.up');
    }

    private static function seconds(string|CarbonImmutable $from, string|CarbonImmutable $to): int
    {
        $from = $from instanceof CarbonImmutable ? $from : CarbonImmutable::parse($from, 'UTC');
        $to = $to instanceof CarbonImmutable ? $to : CarbonImmutable::parse($to, 'UTC');

        return max(0, (int) floor($from->diffInSeconds($to, false)));
    }
}
