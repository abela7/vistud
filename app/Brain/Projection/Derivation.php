<?php

namespace App\Brain\Projection;

use App\Brain\Journal\Authority;
use App\Brain\Journal\Interval;
use App\Brain\Journal\Ref;
use App\Brain\Journal\Vocabulary;
use DateTimeImmutable;

/**
 * rules@1 (ADR 0002 §7): topic labels and flags, misconception and question
 * lifecycles, and the learning profile, derived from a Replay.
 *
 * A "cutoff" limits evidence to entries ordered up to (or strictly before) a
 * timeline key, so earlier states can be recomputed from the same indexes.
 */
final class Derivation
{
    public const LABELS = ['not_started' => 0, 'introduced' => 1, 'developing' => 2, 'working' => 3, 'secure' => 4, 'durable' => 5];

    private const ACTIVE_MISCONCEPTION = ['detected', 'recurring', 'resurfaced', 'addressed'];

    private const RESOLVED_MISCONCEPTION = ['apparently_resolved', 'resolved_retained'];

    private const RESOLVED_QUESTION = ['resolved_demonstrated', 'resolved_learner_confirmed'];

    private float $now;

    private array $memo = [];

    public function __construct(private Replay $r, private Rules $rules, DateTimeImmutable $now)
    {
        $this->now = Interval::epoch($now);
    }

    // ---- topics -----------------------------------------------------------

    public function topics(): array
    {
        $out = [];
        foreach ($this->r->definitions['topic'] ?? [] as $id => $definition) {
            if (($definition['value']['status'] ?? 'active') === 'active' && ! $this->r->isMergedAway('topic', $id)) {
                $out[$id] = $this->topic($id);
            }
        }

        foreach ($out as $id => $topic) {
            // weak_part: a topic that is part of T, directly or through
            // sub-topics, is developing while T is working or better.
            if (self::LABELS[$topic['label']] >= self::LABELS['working']) {
                foreach ($this->r->descendants($id) as $part) {
                    if (($out[$part]['label'] ?? null) === 'developing') {
                        $out[$id]['flags'][] = 'weak_part';
                        break;
                    }
                }
            }
            sort($out[$id]['flags']);
        }

        return $out;
    }

    private function topic(string $t): array
    {
        $window = null;
        $windowEvent = null;
        foreach ($this->attemptsOn($t) as $attempt) {
            if (! $this->isFailure($attempt, $t) || $attempt['cause'] === 'slip') {
                continue;
            }
            $before = $this->label($t, ['key' => $attempt['key'], 'strict' => true], $window);
            if (self::LABELS[$before['label']] >= self::LABELS['working']) {
                $window = $attempt['key'];
                $windowEvent = $attempt['id'];
            }
        }

        $state = $this->label($t, null, $window);
        $flags = [];

        if ($window !== null && $state['label'] === 'developing') {
            $flags[] = 'regressed';
        }
        array_push($flags, ...$this->selfReportFlags($t));
        if ($this->practised($state['qs'])) {
            $flags[] = 'practised';
        }
        $lastContact = $state['contacts'] === [] ? null : max(array_map(fn ($c) => $c['interval']->hi, $state['contacts']));
        $interval = match ($state['label']) {
            'secure' => $this->rules->reviewSecure,
            'durable' => $this->rules->reviewDurable,
            default => null,
        };
        if ($interval !== null && $lastContact !== null && $this->now - $lastContact > $interval) {
            $flags[] = 'needs_review';
        }
        if (isset($this->r->examRelevant[$t])) {
            $flags[] = 'exam_relevant';
        }

        $lastSuccess = $state['qs'] === [] ? null : max(array_map(fn ($a) => $a['interval']->hi, $state['qs']));

        return [
            'label' => $state['label'],
            'flags' => $flags,
            'facts' => [
                'last_contact' => $lastContact === null ? null : Interval::toDateTime($lastContact)->format(DATE_ATOM),
                'last_success' => $lastSuccess === null ? null : Interval::toDateTime($lastSuccess)->format(DATE_ATOM),
                'qualifying_successes' => count($state['qs']),
                'apply_tasks' => $state['apply_tasks'],
                'apply_sessions' => $state['apply_sessions'],
                'explained' => $state['explained'],
                'retained' => $state['retained'],
                'window_start' => $windowEvent,
            ],
        ];
    }

    /**
     * @param  array{key: array, strict: bool}|null  $cutoff
     * @param  array|null  $window  key of the latest regression point
     */
    private function label(string $t, ?array $cutoff, ?array $window): array
    {
        $memoKey = 'label|'.$t.'|'.self::sig($cutoff).'|'.($window === null ? '-' : implode(',', $window));
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $attempts = array_values(array_filter($this->attemptsOn($t), fn ($a) => self::within($a['key'], $cutoff)));
        $contacts = $this->contacts([$t], $cutoff);
        $result = ['label' => 'not_started', 'qs' => [], 'contacts' => $contacts, 'explained' => false, 'retained' => false, 'apply_tasks' => 0, 'apply_sessions' => 0];

        if ($contacts === []) {
            return $this->memo[$memoKey] = $result;
        }
        if ($attempts === []) {
            return $this->memo[$memoKey] = ['label' => 'introduced'] + $result;
        }

        $inWindow = $window === null ? $attempts : array_values(array_filter($attempts, fn ($a) => Replay::compare($a['key'], $window) >= 0));
        $qs = array_values(array_filter($inWindow, fn ($a) => $this->isQualifyingSuccess($a, $t)));
        $apply = array_filter($qs, fn ($a) => $a['form'] === 'apply');
        $tasks = count(array_unique(array_column($apply, 'task')));
        $sessions = count(array_unique(array_filter(array_column($apply, 'session'))));
        $explained = (bool) array_filter($qs, fn ($a) => $a['form'] === 'explain' && $a['own_words'] === true);
        $retained = (bool) array_filter($qs, fn ($a) => $this->retainedOn($a, [$t], $cutoff));

        $result = ['qs' => $qs, 'explained' => $explained, 'retained' => $retained, 'apply_tasks' => $tasks, 'apply_sessions' => $sessions] + $result;

        if ($qs === [] || $this->activeMisconceptionOn($t, $cutoff)) {
            $result['label'] = 'developing';
        } elseif ($tasks >= $this->rules->secureTasks && $sessions >= $this->rules->secureSessions && ($explained || $retained)) {
            $result['label'] = $retained ? 'durable' : 'secure';
        } else {
            $result['label'] = 'working';
        }

        return $this->memo[$memoKey] = $result;
    }

    /** @return list<string> */
    /**
     * ADR 0002 §7 topic flags from self-reports. Only claimed_only also counts
     * reports about a question whose topics include T; overconfident and
     * underconfident look at reports about T itself, as the ADR words them.
     */
    private function selfReportFlags(string $t): array
    {
        $aboutT = fn (array $report) => in_array($t, $report['topics'], true);
        $viaQuestion = function (array $report) use ($t) {
            foreach ($report['questions'] as $question) {
                if (in_array($t, $this->r->questionTopics($question), true)) {
                    return true;
                }
            }

            return false;
        };
        $isConfident = fn (array $r) => in_array($r['stance'], Vocabulary::CONFIDENT_STANCES, true);

        $claims = array_filter($this->r->selfReports, fn ($r) => $isConfident($r) && ($aboutT($r) || $viaQuestion($r)));
        $confident = array_values(array_filter($this->r->selfReports, fn ($r) => $isConfident($r) && $aboutT($r)));
        $uncertain = array_values(array_filter($this->r->selfReports, fn ($r) => in_array($r['stance'], Vocabulary::UNCERTAIN_STANCES, true) && $aboutT($r)));
        $attempts = $this->attemptsOn($t);
        $successes = array_values(array_filter($attempts, fn ($a) => $this->isQualifyingSuccess($a, $t)));
        $flags = [];

        if ($claims !== [] && $attempts === []) {
            $flags[] = 'claimed_only';
        }
        if ($confident !== []) {
            $latest = end($confident);
            foreach ($attempts as $failure) {
                if (Replay::compare($failure['key'], $latest['key']) > 0 && $this->isFailure($failure, $t)) {
                    $recovered = array_filter($successes, fn ($s) => Replay::compare($s['key'], $failure['key']) > 0);
                    if ($recovered === []) {
                        $flags[] = 'overconfident';
                        break;
                    }
                }
            }
        }
        if ($uncertain !== []) {
            $latest = end($uncertain);
            $after = array_filter($successes, fn ($s) => Replay::compare($s['key'], $latest['key']) > 0);
            if (count($after) >= 2) {
                $flags[] = 'underconfident';
            }
        }

        return $flags;
    }

    private function practised(array $qs): bool
    {
        $sessions = array_unique(array_filter(array_column($qs, 'session')));
        if (count($sessions) < $this->rules->practisedSessions) {
            return false;
        }

        return Interval::guaranteedGap($qs[0]['interval'], end($qs)['interval']) >= $this->rules->practisedSpan;
    }

    private function isQualifyingSuccess(array $attempt, string $t): bool
    {
        $outcome = $attempt['topics'][$t] ?? null;

        return is_array($outcome)
            && $outcome['value'] === 'correct'
            && $outcome['rank'] !== Authority::SELF
            && $attempt['support'] === 'unaided'
            && $attempt['repeat'] !== 'immediate';
    }

    private function isFailure(array $attempt, string $t): bool
    {
        $outcome = $attempt['topics'][$t] ?? null;

        return is_array($outcome)
            && in_array($outcome['value'], ['incorrect', 'partial'], true)
            && $attempt['support'] === 'unaided';
    }

    /** @return list<array> attempts on $t, in timeline order */
    private function attemptsOn(string $t): array
    {
        return $this->memo['attempts|'.$t] ??= array_values(array_filter(
            $this->r->attempts,
            fn (array $a) => array_key_exists($t, $a['topics']),
        ));
    }

    /** @param list<string> $topics @return list<array> attempts and teaching on any of the topics */
    private function contacts(array $topics, ?array $cutoff): array
    {
        $contacts = [];
        foreach ($topics as $t) {
            foreach ($this->attemptsOn($t) as $attempt) {
                $contacts[$attempt['id']] = $attempt;
            }
            foreach ($this->r->exposures as $exposure) {
                if ($this->r->teaches($exposure, $t)) {
                    $contacts[$exposure['id']] = $exposure;
                }
            }
        }

        return array_values(array_filter($contacts, fn ($c) => self::within($c['key'], $cutoff)));
    }

    /** ADR 0002 §7: every contact that possibly came before s is at least the retention gap earlier. */
    private function retainedOn(array $success, array $topics, ?array $cutoff): bool
    {
        $prior = array_filter(
            $this->contacts($topics, $cutoff),
            fn ($c) => $c['id'] !== $success['id'] && $c['interval']->lo <= $success['interval']->hi,
        );
        if ($prior === []) {
            return false;
        }
        foreach ($prior as $contact) {
            if (Interval::guaranteedGap($contact['interval'], $success['interval']) < $this->rules->retentionGap) {
                return false;
            }
        }

        return true;
    }

    // ---- misconceptions ---------------------------------------------------

    public function misconceptions(): array
    {
        $ids = array_unique([...array_keys($this->r->definitions['misconception'] ?? []), ...array_keys($this->r->misconceptionDefiners)]);
        $out = [];
        foreach ($ids as $m) {
            if ($this->r->isMergedAway('misconception', $m)) {
                continue;
            }
            $status = $this->misconceptionStatus($m, null);
            if ($status['state'] !== null) {
                $out[$m] = $status + ['topics' => $this->r->misconceptionTopics($m)];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array{state: ?string, resurfaced_count: int} */
    private function misconceptionStatus(string $m, ?array $cutoff): array
    {
        if (($this->r->definitions['misconception'][$m]['value']['status'] ?? 'active') !== 'active') {
            return ['state' => null, 'resurfaced_count' => 0];
        }
        if (! isset($this->r->definitions['misconception'][$m])) {
            $rejected = array_filter($this->r->misconceptionDefiners[$m] ?? [], fn ($id) => ($this->r->status[$id] ?? null) === 'rejected');

            return ['state' => $rejected === [] ? null : 'withdrawn', 'resurfaced_count' => 0];
        }
        if ($this->isDisputed($m, $cutoff)) {
            return ['state' => 'disputed', 'resurfaced_count' => 0];
        }

        $resurfaced = 0;
        foreach ($this->exhibits($m, $cutoff) as $exhibit) {
            $prior = $this->misconceptionState($m, ['key' => $exhibit['key'], 'strict' => true]);
            if (in_array($prior, self::RESOLVED_MISCONCEPTION, true)) {
                $resurfaced++;
            }
        }

        return ['state' => $this->misconceptionState($m, $cutoff), 'resurfaced_count' => $resurfaced];
    }

    private function misconceptionState(string $m, ?array $cutoff): ?string
    {
        $memoKey = 'misc|'.$m.'|'.self::sig($cutoff);
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $exhibits = $this->exhibits($m, $cutoff);
        if ($exhibits === []) {
            return $this->memo[$memoKey] = null;
        }

        $latest = end($exhibits);
        $prior = $this->misconceptionState($m, ['key' => $latest['key'], 'strict' => true]);
        $topics = $this->r->misconceptionTopics($m);

        $counters = array_values(array_filter($this->r->attempts, fn ($a) => Replay::compare($a['key'], $latest['key']) > 0
            && self::within($a['key'], $cutoff)
            && ($a['misc'][$m] ?? null) === false
            && (bool) array_filter($topics, fn ($t) => $this->isQualifyingSuccess($a, $t))));

        $addressed = (bool) array_filter($this->r->exposures, fn ($e) => Replay::compare($e['key'], $latest['key']) > 0
            && self::within($e['key'], $cutoff)
            && in_array(Ref::make('misconception', $m), $this->r->addresses[$e['id']] ?? [], true));

        $retained = false;
        foreach ($counters as $i => $counter) {
            if ($this->apparentlyResolved(array_slice($counters, 0, $i + 1), $latest) && $this->retainedOn($counter, $topics, $cutoff)) {
                $retained = true;
                break;
            }
        }

        $state = match (true) {
            $retained => 'resolved_retained',
            $this->apparentlyResolved($counters, $latest) => 'apparently_resolved',
            $addressed => 'addressed',
            in_array($prior, self::RESOLVED_MISCONCEPTION, true) => 'resurfaced',
            count(array_unique(array_column($exhibits, 'task'))) >= 2 => 'recurring',
            default => 'detected',
        };

        return $this->memo[$memoKey] = $state;
    }

    private function apparentlyResolved(array $counters, array $latestExhibit): bool
    {
        if (count(array_unique(array_column($counters, 'task'))) < $this->rules->resolveCounterTasks) {
            return false;
        }
        foreach ($counters as $counter) {
            if ($counter['session'] !== null && $counter['session'] !== $latestExhibit['session']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array> */
    private function exhibits(string $m, ?array $cutoff): array
    {
        return array_values(array_filter(
            $this->r->attempts,
            fn ($a) => ($a['misc'][$m] ?? null) === true && self::within($a['key'], $cutoff),
        ));
    }

    /** A dispute on the misconception's definition holds until withdrawn or until a later attempt exhibits it again. */
    private function isDisputed(string $m, ?array $cutoff): bool
    {
        $definers = array_map(fn ($id) => Ref::make('claim', $id), $this->r->misconceptionDefiners[$m] ?? []);
        foreach ($this->r->disputes as $dispute) {
            if (! in_array($dispute['target'], $definers, true)) {
                continue;
            }
            $lifted = array_filter($this->exhibits($m, $cutoff), fn ($a) => $a['interval']->lo > $dispute['lo']);
            if ($lifted === []) {
                return true;
            }
        }

        return false;
    }

    private function activeMisconceptionOn(string $t, ?array $cutoff): bool
    {
        foreach (array_keys($this->r->definitions['misconception'] ?? []) as $m) {
            if ($this->r->isMergedAway('misconception', $m) || ! in_array($t, $this->r->misconceptionTopics($m), true)) {
                continue;
            }
            if (in_array($this->misconceptionStatus($m, $cutoff)['state'], self::ACTIVE_MISCONCEPTION, true)) {
                return true;
            }
        }

        return false;
    }

    // ---- questions --------------------------------------------------------

    public function questions(): array
    {
        $out = [];
        foreach ($this->r->definitions['question'] ?? [] as $q => $definition) {
            if (($definition['value']['status'] ?? 'active') === 'active' && ! $this->r->isMergedAway('question', $q)) {
                $out[$q] = $this->question($q);
            }
        }
        ksort($out);

        return $out;
    }

    private function question(string $q): array
    {
        $events = [];
        $asks = [];
        $reports = [];
        foreach ($this->r->asks as $ask) {
            if (in_array($q, $ask['questions'], true)) {
                $events[] = ['type' => 'ask'] + $ask;
                $asks[] = $ask['id'];
            }
        }
        if ($asks === []) {
            // Defined, but nothing has asked it yet: open, with no evidence.
            return ['state' => 'open', 'flags' => $this->identityFlags($q), 'resurfaced_count' => 0];
        }
        foreach ($this->r->selfReports as $report) {
            if (in_array($q, $report['questions'], true)) {
                $events[] = ['type' => 'report'] + $report;
                $reports[] = $report['id'];
            }
        }
        foreach ($this->r->exposures as $exposure) {
            if (array_intersect($exposure['responds_to'], [...$asks, ...$reports]) !== [] || isset($exposure['answers'][$q])) {
                $events[] = ['type' => 'answer', 'adequacy' => $exposure['answers'][$q] ?? null] + $exposure;
            }
        }
        foreach ($this->r->attempts as $attempt) {
            if (isset($attempt['demo'][$q]) && $attempt['support'] === 'unaided'
                && is_array($attempt['overall']) && $attempt['overall']['value'] === 'correct') {
                $events[] = ['type' => 'demo'] + $attempt;
            }
        }
        usort($events, fn ($a, $b) => Replay::compare($a['key'], $b['key']));

        $open = null;
        $resurfaced = 0;
        $reopened = false;
        foreach ($events as $i => $event) {
            $isAsk = $event['type'] === 'ask';
            $isUncertain = $event['type'] === 'report' && in_array($event['stance'], Vocabulary::UNCERTAIN_STANCES, true);
            if (! $isAsk && ! ($isUncertain && $open !== null)) {
                continue;
            }
            $resolved = $open !== null
                && in_array($this->questionStateAfter($open, array_slice($events, 0, $i))['state'], self::RESOLVED_QUESTION, true);
            if ($isAsk) {
                $resurfaced += $resolved ? 1 : 0;
                $open = $event;
                $reopened = false;
            } elseif ($resolved) {
                $resurfaced++;
                $open = $event;
                $reopened = true;
            }
        }

        $result = $this->questionStateAfter($open, $events);
        $flags = $result['flags'];
        if ($reopened) {
            $flags[] = 'reopened';
        }
        $lastEvidence = max(array_map(fn ($e) => $e['interval']->hi, $events));
        if (in_array($result['state'], ['open', 'being_answered', 'partially_answered'], true)
            && $this->now - $lastEvidence >= $this->rules->dormantAfter) {
            $flags[] = 'dormant';
        }
        array_push($flags, ...$this->identityFlags($q));
        sort($flags);

        return ['state' => $result['state'], 'flags' => $flags, 'resurfaced_count' => $resurfaced];
    }

    /** @return list<string> merged (others were merged into it) and split (it was split) */
    private function identityFlags(string $q): array
    {
        return array_values(array_filter([
            isset($this->r->mergedInto['question'][$q]) ? 'merged' : null,
            isset($this->r->splitParents['question'][$q]) ? 'split' : null,
        ]));
    }

    /** ADR 0002 §7 question rules, over the evidence after the latest opening event. */
    private function questionStateAfter(array $open, array $events): array
    {
        $after = array_values(array_filter($events, fn ($e) => Replay::compare($e['key'], $open['key']) > 0));
        $answers = array_values(array_filter($after, fn ($e) => $e['type'] === 'answer'));

        if (array_filter($after, fn ($e) => $e['type'] === 'demo')) {
            return ['state' => 'resolved_demonstrated', 'flags' => []];
        }

        $confirmations = array_values(array_filter($after, fn ($e) => $e['type'] === 'report'
            && in_array($e['stance'], Vocabulary::CONFIDENT_STANCES, true)
            && array_filter($answers, fn ($a) => Replay::compare($a['key'], $e['key']) < 0)));
        if ($confirmations !== []) {
            $confirmation = end($confirmations);
            $answered = array_values(array_filter($answers, fn ($a) => Replay::compare($a['key'], $confirmation['key']) < 0));
            if (in_array(end($answered)['adequacy'], ['partial', 'none'], true)) {
                return ['state' => 'partially_answered', 'flags' => ['learner_thought_resolved']];
            }

            return ['state' => 'resolved_learner_confirmed', 'flags' => []];
        }

        if (array_filter($answers, fn ($a) => $a['adequacy'] === 'full')) {
            return ['state' => 'answered', 'flags' => []];
        }

        $doubtAfterAnswer = array_filter($after, fn ($e) => $e['type'] === 'report'
            && in_array($e['stance'], Vocabulary::UNCERTAIN_STANCES, true)
            && array_filter($answers, fn ($a) => Replay::compare($a['key'], $e['key']) < 0));
        if (array_filter($answers, fn ($a) => in_array($a['adequacy'], ['partial', 'none'], true)) || $doubtAfterAnswer) {
            return ['state' => 'partially_answered', 'flags' => []];
        }

        return ['state' => $answers === [] ? 'open' : 'being_answered', 'flags' => []];
    }

    // ---- profile and attempts --------------------------------------------

    public function profile(): array
    {
        $profile = [];
        foreach ($this->r->exposures as $exposure) {
            foreach ($exposure['effects'] as $target => $effect) {
                foreach ($exposure['approach'] as $approach) {
                    $profile[$approach] ??= ['helped' => 0, 'no_effect' => 0, 'confused' => 0, 'examples' => []];
                    $profile[$approach][$effect]++;
                    $profile[$approach]['examples'][] = $target;
                }
            }
        }
        ksort($profile);

        return $profile;
    }

    public function attempts(): array
    {
        return array_map(fn (array $a) => [
            'task' => $a['task'],
            'overall' => is_array($a['overall']) ? $a['overall']['value'] : $a['overall'],
            'topics' => array_map(fn ($o) => is_array($o) ? $o['value'] : $o, $a['topics']),
            'misconceptions' => $a['misc'],
            'repeat' => $a['repeat'],
            'checker_suspect' => $a['checker_suspect'],
            'overridden' => $a['overridden'],
        ], $this->r->attempts);
    }

    // ---- helpers ----------------------------------------------------------

    private static function within(array $key, ?array $cutoff): bool
    {
        if ($cutoff === null) {
            return true;
        }
        $comparison = Replay::compare($key, $cutoff['key']);

        return $cutoff['strict'] ? $comparison < 0 : $comparison <= 0;
    }

    private static function sig(?array $cutoff): string
    {
        return $cutoff === null ? 'all' : $cutoff['key'][0].','.$cutoff['key'][1].','.(int) $cutoff['strict'];
    }
}
