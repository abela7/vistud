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
use Illuminate\Database\UniqueConstraintViolationException;
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
    /** What a session is for (docs/specs/vistud-2-blueprint.md §3.9): never mixed. */
    public const MODES = ['module', 'topic', 'quiz', 'test', 'free'];

    public const IDLE_MINUTES = 30;

    public const BREAK_MINUTES = 60;

    public const AUTO_END_HOURS = 12;

    public const MAX_LOG_MINUTES = 12 * 60;

    /** The tutor's summary of a session and its checkpoint, in characters. */
    public const SUMMARY_LIMIT = 2000;

    public const CHECKPOINT_LIMIT = 1000;

    /** The Pomodoro presets: name, focus, short break, long break (minutes), and focus periods before a long break. */
    public const POMODORO_PRESETS = [
        'classic' => ['Classic', 25, 5, 15, 4],
        'deep' => ['Deep work', 50, 10, 30, 2],
        'short' => ['Short bursts', 15, 3, 10, 4],
    ];

    /** The range each Pomodoro setting may take. */
    public const POMODORO_LIMITS = ['focus' => [5, 120], 'short' => [1, 30], 'long' => [5, 60], 'every' => [2, 8]];

    public function __construct(private Memory $memory, private LearnerProfiles $profiles) {}

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
     * Every study session of a module, newest first: started in it, or on
     * one of its topics without a module. Its Study sessions page lists them
     * and the module's page counts them.
     *
     * @return list<SessionDetails>
     */
    public function forModule(Principal $by, string $workspaceId, string $moduleId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $open = $this->openRow($scope);
        if ($open !== null && $open->workspace_id === $workspaceId) {
            $this->settled($scope, $by, $open->id);
        }
        $topics = LearnerTables::query($scope, 'topics')->where('module_id', $moduleId)->pluck('id')->all();

        return LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q->where('module_id', $moduleId)->orWhere(fn ($q) => $q->whereNull('module_id')->whereIn('topic_id', $topics)))
            ->orderByDesc('started_at')->orderByDesc('id')->get()
            ->map(fn ($row) => $this->details($scope, $row))->all();
    }

    /**
     * Study time in a workspace: since $since (the start of the week, say),
     * and in all. A session counts where it started.
     *
     * @return array{since: int, all: int, sessions: int, pomodoros_since: int, pomodoros: int}
     */
    public function totals(Principal $by, string $workspaceId, CarbonImmutable $since): array
    {
        $totals = ['since' => 0, 'all' => 0, 'sessions' => 0, 'pomodoros_since' => 0, 'pomodoros' => 0];
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)->get();
        foreach ($rows as $row) {
            $seconds = $row->state === 'ended' ? (int) $row->study_seconds : $this->details($scope, $row)->studySeconds;
            $totals['all'] += $seconds;
            $totals['sessions']++;
            $totals['pomodoros'] += (int) $row->pomodoros;
            if (CarbonImmutable::parse($row->started_at, 'UTC')->greaterThanOrEqualTo($since)) {
                $totals['since'] += $seconds;
                $totals['pomodoros_since'] += (int) $row->pomodoros;
            }
        }

        return $totals;
    }

    /**
     * The student's rhythm: study time on each of the last $days days, by
     * their own calendar (oldest first, today last), in one workspace or
     * (null) in all; and the streak: days in a row with study time, in any
     * workspace, up to today (or up to yesterday while today has none yet).
     *
     * @return array{days: array<string, int>, streak: int}
     */
    public function rhythm(Principal $by, ?string $workspaceId = null, int $days = 7): array
    {
        $scope = Guard::learner($by);
        if ($workspaceId !== null) {
            Input::workspace($scope, $workspaceId);
        }
        $zone = $this->timezone($by);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $week = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $week[$today->subDays($i)->toDateString()] = 0;
        }

        $studied = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->get() as $row) {
            $seconds = $row->state === 'ended' ? (int) $row->study_seconds : $this->details($scope, $row)->studySeconds;
            if ($seconds <= 0) {
                continue;
            }
            $date = CarbonImmutable::parse($row->started_at, 'UTC')->setTimezone($zone)->toDateString();
            $studied[$date] = true;
            if (isset($week[$date]) && ($workspaceId === null || $row->workspace_id === $workspaceId)) {
                $week[$date] += $seconds;
            }
        }

        $streak = 0;
        $day = isset($studied[$today->toDateString()]) ? $today : $today->subDay();
        while (isset($studied[$day->toDateString()])) {
            $streak++;
            $day = $day->subDay();
        }

        return ['days' => $week, 'streak' => $streak];
    }

    /**
     * Starts studying in a workspace, on a topic and in a module if given,
     * with the free clock or (given its settings) the Pomodoro clock.
     * Another open session is a conflict.
     *
     * @param  ?array{focus?: mixed, short?: mixed, long?: mixed, every?: mixed, auto?: mixed}  $pomodoro
     * @param  ?array{method?: mixed, check_ins?: mixed, quiz?: mixed, pace?: mixed}  $tutoring  how the assistant should teach; the student's answers in "How you learn", else the defaults, when null
     * @param  ?string  $mode  one of MODES; not given, a topic means topic, a module alone means module and neither means free
     */
    public function start(Principal $by, string $workspaceId, ?string $topicId = null, ?string $moduleId = null, ?array $pomodoro = null, ?array $tutoring = null, ?string $mode = null): SessionDetails
    {
        Input::refuse($mode === null || in_array($mode, self::MODES, true) ? [] : ['mode' => 'Unknown way to study.']);
        $scope = Guard::learner($by);
        $pomodoro = $pomodoro === null ? null : self::pomodoroSettings($pomodoro);
        // Not chosen: the way the student said they like to learn in this course, if they said.
        $tutoring = Tutoring::validated($tutoring ?? LearnerProfiles::teaching($this->profiles->get($by, $workspaceId)));
        $open = $this->openRow($scope);
        if ($open !== null && $this->settled($scope, $by, $open->id)->state !== 'ended') {
            throw new Conflict('session_open', 'Another session is still open. End it first.', ['session' => $open->id, 'workspace' => $open->workspace_id]);
        }
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $topicId, $moduleId, $pomodoro, $tutoring, $mode, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            [$topicId, $moduleId] = $this->place($scope, $workspaceId, $topicId, $moduleId);
            $mode ??= $topicId !== null ? 'topic' : ($moduleId !== null ? 'module' : 'free');
            $now = self::now();
            // The database keeps one open session per student (open_learner is unique): a start in another tab at the same moment loses.
            try {
                LearnerTables::insert($scope, 'study_sessions', [
                    'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'topic_id' => $topicId, 'mode' => $mode,
                    'pomodoro' => $pomodoro === null ? null : json_encode($pomodoro), 'phase' => $pomodoro === null ? null : 'focus',
                    'phase_started_at' => $pomodoro === null ? null : $now, 'tutoring' => json_encode($tutoring),
                    'state' => 'running', 'started_at' => $now, 'last_activity_at' => $now, 'revision' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new Conflict('session_open', 'Another session is still open. End it first.');
            }
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

    /**
     * Back to studying, after a pause or a break. On the Pomodoro clock, a
     * pause in a Pomodoro break goes back to the break, and a Pomodoro break
     * resumed early starts the next focus period.
     */
    public function resume(Principal $by, string $id): void
    {
        $this->change($by, $id, ['paused', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            $inBreak = in_array($row->phase, ['short_break', 'long_break'], true);
            $this->closeOpen($scope, $row, $now, 'resume');
            if ($inBreak && $row->state === 'paused') {
                $this->openSegment($scope, $row->id, 'break', $now);

                return ['state' => 'break', 'paused_by' => null];
            }
            $this->openSegment($scope, $row->id, 'study', $now);
            $phase = $row->phase !== null && ($inBreak || $row->paused_by === 'pomodoro') ? ['phase' => 'focus', 'phase_started_at' => $now] : [];

            return ['state' => 'running', 'paused_by' => null] + $phase;
        });
    }

    /**
     * The Pomodoro clock's next phase, now: a focus period ends early (it
     * doesn't count as a pomodoro) and a short break starts, or a break ends
     * and the next focus period starts.
     */
    public function skip(Principal $by, string $id): void
    {
        $this->change($by, $id, ['running', 'paused', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) {
            if ($row->phase === null) {
                throw new Conflict('not_pomodoro', 'This session uses the free clock.');
            }
            $this->closeOpen($scope, $row, $now, 'skip');
            if ($row->phase === 'focus') {
                $this->openSegment($scope, $row->id, 'break', $now);

                return ['state' => 'break', 'paused_by' => null, 'phase' => 'short_break', 'phase_started_at' => $now, 'pomodoros_skipped' => $row->pomodoros_skipped + 1];
            }
            $this->openSegment($scope, $row->id, 'study', $now);

            return ['state' => 'running', 'paused_by' => null, 'phase' => 'focus', 'phase_started_at' => $now];
        });
    }

    /**
     * Turns the Pomodoro clock on (a focus period starts counting now) or,
     * given null, off: the free clock carries on from here.
     *
     * @param  ?array{focus?: mixed, short?: mixed, long?: mixed, every?: mixed, auto?: mixed}  $settings
     */
    public function setPomodoro(Principal $by, string $id, ?array $settings): void
    {
        $settings = $settings === null ? null : self::pomodoroSettings($settings);
        $this->change($by, $id, ['running', 'paused', 'break'], function (LearnerScope $scope, object $row, CarbonImmutable $now) use ($settings) {
            if ($settings === null) {
                return ['pomodoro' => null, 'phase' => null, 'phase_started_at' => null, 'paused_by' => $row->paused_by === 'pomodoro' ? 'student' : $row->paused_by];
            }
            $keep = $row->phase !== null && $row->pomodoro !== null;

            return ['pomodoro' => json_encode($settings)] + ($keep ? [] : ['phase' => 'focus', 'phase_started_at' => $now]);
        });
    }

    /**
     * How the assistant should teach from now on (App\Study\Tutoring).
     *
     * @param  array{method?: mixed, check_ins?: mixed, quiz?: mixed, pace?: mixed}  $choices
     */
    public function setTutoring(Principal $by, string $id, array $choices): void
    {
        $choices = Tutoring::validated($choices);
        $this->change($by, $id, ['running', 'paused', 'break'], fn () => ['tutoring' => json_encode($choices)]);
    }

    /**
     * What the open session is about: a topic of its workspace, or none. The session keeps its module; one without
     * a module takes the topic's. A new revision of its journal record says so.
     */
    public function setTopic(Principal $by, string $id, ?string $topicId): SessionDetails
    {
        $this->change($by, $id, ['running', 'paused', 'break'], function (LearnerScope $scope, object $row) use ($topicId) {
            [$topicId, $topicModule] = $this->place($scope, (string) $row->workspace_id, $topicId, null);

            return ['topic_id' => $topicId, 'module_id' => $row->module_id ?? $topicModule];
        }, journal: true);

        return $this->find($by, $id);
    }

    /** Puts a note or file of the workspace (`note:{id}`, `file:{id}`) in the session's material, or takes it out. */
    public function toggleMaterial(Principal $by, string $id, string $item): void
    {
        $this->change($by, $id, ['running', 'paused', 'break'], function (LearnerScope $scope, object $row) use ($item) {
            [$type, $itemId] = array_pad(explode(':', $item, 2), 2, '');
            $exists = in_array($type, ['note', 'file'], true) && LearnerTables::query($scope, $type === 'note' ? 'notes' : 'files')
                ->where('id', $itemId)->where('workspace_id', $row->workspace_id)->whereNull('trashed_at')->exists();
            if (! $exists) {
                throw new NotFound;
            }
            $material = json_decode((string) $row->material, true) ?: [];
            $material = in_array($item, $material, true) ? array_values(array_diff($material, [$item])) : [...$material, $item];

            return ['material' => $material === [] ? null : json_encode($material)];
        });
    }

    /** The tutor's summary for the next session (the write-back), open or ended. */
    public function setSummary(Principal $by, string $id, string $summary): void
    {
        $this->setText($by, $id, 'summary', $summary, self::SUMMARY_LIMIT);
    }

    /** Where the session last stood (the tutor's latest checkpoint), open or ended. */
    public function setCheckpoint(Principal $by, string $id, string $checkpoint): void
    {
        $this->setText($by, $id, 'checkpoint', $checkpoint, self::CHECKPOINT_LIMIT);
    }

    /** @return list<string> fingerprints of the marks already saved from this session's chat */
    public function captured(Principal $by, string $id): array
    {
        $row = LearnerTables::query(Guard::learner($by), 'study_sessions')->where('id', $id)->first() ?? throw new NotFound;

        return json_decode((string) $row->captured, true) ?: [];
    }

    /** Remembers marks as saved, and (given one) the session note they went into. */
    public function remember(Principal $by, string $id, array $fingerprints, ?string $noteId = null): void
    {
        $scope = Guard::learner($by);
        DB::transaction(function () use ($scope, $id, $fingerprints, $noteId) {
            $row = $this->lock($scope, $id);
            $captured = array_values(array_unique([...(json_decode((string) $row->captured, true) ?: []), ...$fingerprints]));
            LearnerTables::query($scope, 'study_sessions')->where('id', $id)->update(array_filter([
                'captured' => json_encode($captured), 'note_id' => $noteId, 'updated_at' => self::now(),
            ], fn ($v) => $v !== null));
        });
    }

    /** The session note's id, if the write-back made one. */
    public function noteId(Principal $by, string $id): ?string
    {
        $row = LearnerTables::query(Guard::learner($by), 'study_sessions')->where('id', $id)->first() ?? throw new NotFound;

        return $row->note_id;
    }

    private function setText(Principal $by, string $id, string $column, string $text, int $limit): void
    {
        $scope = Guard::learner($by);
        $text = trim($text);
        Input::refuse($text === '' ? [$column => 'Write something.'] : (mb_strlen($text) > $limit ? [$column => "Keep it to {$limit} characters."] : []));
        $this->lock($scope, $id);
        LearnerTables::query($scope, 'study_sessions')->where('id', $id)->update([$column => $text, 'updated_at' => self::now()]);
    }

    /**
     * The clock and teaching to offer a new session: the way the last one in this course went, so a session starts
     * as the student left off; or, in a course with no session yet (or after the student has answered "How you
     * learn" again since), their answers there; or the way the last one anywhere went; or the plain defaults.
     *
     * @return array{pomodoro: ?array, tutoring: array}
     */
    public function lastChoices(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        $here = LearnerTables::query($scope, 'study_sessions')->where('manual', false)->where('workspace_id', $workspaceId)->orderByDesc('started_at')->first();
        $row = $here ?? LearnerTables::query($scope, 'study_sessions')->where('manual', false)->orderByDesc('started_at')->first();
        $profile = $this->profiles->get($by, $workspaceId);
        $taught = LearnerProfiles::teaching($profile);
        $profileWins = $taught !== null && ($here === null || ($profile->updatedAt !== null && $profile->updatedAt > $here->started_at));

        return [
            'pomodoro' => $row?->pomodoro === null ? null : json_decode((string) $row->pomodoro, true),
            'tutoring' => $profileWins ? $taught : Tutoring::normalised($row?->tutoring === null ? null : json_decode((string) $row->tutoring, true)),
        ];
    }

    /**
     * Checked Pomodoro settings, in whole minutes.
     *
     * @return array{focus: int, short: int, long: int, every: int, auto: bool}
     */
    public static function pomodoroSettings(array $input): array
    {
        $settings = [];
        $errors = [];
        foreach (self::POMODORO_LIMITS as $key => [$min, $max]) {
            $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                $errors["pomodoro.{$key}"] = "From {$min} to {$max}.";
            }
            $settings[$key] = (int) $value;
        }
        Input::refuse($errors);

        return $settings + ['auto' => filter_var($input['auto'] ?? false, FILTER_VALIDATE_BOOL)];
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
            // What was recorded in it stays: a quiz, a test.
            LearnerTables::query($scope, 'quizzes')->where('session_id', $id)->update(['session_id' => null]);
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
            $now = self::now();
            // The Pomodoro phases that ended since, each at its exact moment.
            for ($steps = 0; $steps < 200 && $this->pomodoroStep($scope, $this->lock($scope, $id), $now); $steps++);
            $row = $this->lock($scope, $id);
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

    /**
     * Ends the Pomodoro phase that is over by $now, if one is: a focus
     * period becomes a pomodoro and its break starts where it ended; a break
     * ends where it ran out, and the next focus period starts by itself or
     * waits for the student. A focus period doesn't end in time the idle
     * rule takes back: once the student is away, only before their last
     * activity.
     */
    private function pomodoroStep(LearnerScope $scope, object $row, CarbonImmutable $now): bool
    {
        $boundary = $this->phaseBoundary($scope, $row);
        if ($boundary === null || $boundary->greaterThan($now)) {
            return false;
        }
        $settings = json_decode((string) $row->pomodoro, true);
        if ($row->phase === 'focus') {
            // Once the student is away, time after their last activity isn't study (the idle rule), so it can't finish a pomodoro.
            $last = CarbonImmutable::parse($row->last_activity_at, 'UTC');
            if ($now->greaterThan($last->addMinutes(self::IDLE_MINUTES)) && $boundary->greaterThan($last)) {
                return false;
            }
            $this->closeOpen($scope, $row, $boundary, 'pomodoro');
            $this->openSegment($scope, $row->id, 'break', $boundary);
            $done = (int) $row->pomodoros + 1;
            $fields = ['state' => 'break', 'paused_by' => null, 'phase' => $done % $settings['every'] === 0 ? 'long_break' : 'short_break', 'pomodoros' => $done];
        } else {
            $this->closeOpen($scope, $row, $boundary, 'pomodoro');
            if ($settings['auto']) {
                $this->openSegment($scope, $row->id, 'study', $boundary);
                $fields = ['state' => 'running', 'paused_by' => null, 'phase' => 'focus'];
            } else {
                $fields = ['state' => 'paused', 'paused_by' => 'pomodoro', 'phase' => 'focus'];
            }
        }
        LearnerTables::query($scope, 'study_sessions')->where('id', $row->id)->update($fields + ['phase_started_at' => $boundary, 'updated_at' => $now]);

        return true;
    }

    /**
     * When the current Pomodoro phase runs out, if its clock is running:
     * focus counts study time since the phase began, a break counts break
     * time. Null when the phase isn't counting now.
     */
    private function phaseBoundary(LearnerScope $scope, object $row): ?CarbonImmutable
    {
        if ($row->phase === null || $row->pomodoro === null || $row->phase_started_at === null) {
            return null;
        }
        $kind = $row->phase === 'focus' ? 'study' : 'break';
        if (($kind === 'study' && $row->state !== 'running') || ($kind === 'break' && $row->state !== 'break')) {
            return null;
        }
        $open = $this->openSegmentRow($scope, $row->id);
        if ($open === null || $open->kind !== $kind) {
            return null;
        }
        $since = CarbonImmutable::parse($row->phase_started_at, 'UTC');
        $done = $this->phaseSeconds($scope, $row, $kind, $since, null);
        $openFrom = max($since, CarbonImmutable::parse($open->started_at, 'UTC'));

        return $openFrom->addSeconds(max(0, self::phaseLength($row) - $done));
    }

    /** Seconds of $kind since the phase began, in closed segments, plus the open one up to $now if given. */
    private function phaseSeconds(LearnerScope $scope, object $row, string $kind, CarbonImmutable $since, ?CarbonImmutable $now): int
    {
        $seconds = 0;
        $segments = LearnerTables::query($scope, 'session_segments')->where('session_id', $row->id)->where('kind', $kind)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $since))->get();
        foreach ($segments as $segment) {
            if ($segment->ended_at === null && $now === null) {
                continue;
            }
            $from = max($since, CarbonImmutable::parse($segment->started_at, 'UTC'));
            $seconds += self::seconds($from, $segment->ended_at === null ? $now : CarbonImmutable::parse($segment->ended_at, 'UTC'));
        }

        return $seconds;
    }

    /** How long the current phase lasts, in seconds. */
    private static function phaseLength(object $row): int
    {
        $settings = json_decode((string) $row->pomodoro, true);

        return 60 * (int) ($settings[['focus' => 'focus', 'short_break' => 'short', 'long_break' => 'long'][$row->phase] ?? 'focus'] ?? 0);
    }

    /** Whether any rule applies now (checked without a lock first). */
    private function due(LearnerScope $scope, object $row, CarbonImmutable $now): bool
    {
        $boundary = $this->phaseBoundary($scope, $row);
        if ($boundary !== null && $boundary->lessThanOrEqualTo($now)) {
            return true;
        }
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
                'pomodoros' => $row->state === 'ended' && $row->pomodoro !== null ? (int) $row->pomodoros : null,
                'tutoring' => $row->state === 'ended' && $row->tutoring !== null ? json_decode((string) $row->tutoring, true) : null,
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

        $pomodoro = $row->pomodoro === null ? null : json_decode((string) $row->pomodoro, true);
        $phaseElapsed = 0;
        if ($pomodoro !== null && $row->phase !== null && $row->phase_started_at !== null && $row->state !== 'ended' && $row->paused_by !== 'pomodoro') {
            $phaseElapsed = $this->phaseSeconds($scope, $row, $row->phase === 'focus' ? 'study' : 'break', CarbonImmutable::parse($row->phase_started_at, 'UTC'), self::now());
        }

        return new SessionDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->topic_id, $row->state, $row->paused_by, (bool) $row->manual,
            self::iso($row->started_at), $row->ended_at === null ? null : self::iso($row->ended_at), self::iso($row->last_activity_at),
            $study, $break, $openKind, $openSince, $segments,
            $pomodoro, $row->phase, $pomodoro === null || $row->phase === null ? 0 : self::phaseLength($row), $phaseElapsed,
            (int) $row->pomodoros, (int) $row->pomodoros_skipped,
            Tutoring::normalised($row->tutoring === null ? null : json_decode((string) $row->tutoring, true)),
            $row->material === null ? [] : array_values(json_decode((string) $row->material, true) ?: []),
            $row->summary, $row->checkpoint,
            in_array($row->mode ?? null, self::MODES, true) ? $row->mode : 'topic',
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
