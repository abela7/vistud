<?php

namespace App\Brain\Projection;

use App\Brain\Journal\Authority;
use App\Brain\Journal\Interval;
use App\Brain\Journal\JournalEntry;
use App\Brain\Journal\Kind;
use App\Brain\Journal\Ref;
use App\Brain\Journal\ReviewPolicy;

/**
 * Indexes one learner's journal for a single projection run: which claims are
 * effective, identity redirects, relations, and the effective facets of every
 * attempt and exposure (ADR 0002 §6–7). Pure and deterministic.
 */
final class Replay
{
    public const DISPUTED = 'disputed';

    /** @var array<string, JournalEntry> every entry in view, by id */
    public array $entries = [];

    /** @var array<string, array> effective bodies after amendments */
    public array $bodies = [];

    /** @var array<string, true> */
    public array $retracted = [];

    /** @var array<string, string> claim id => accepted|pending|rejected */
    public array $status = [];

    /** @var list<array{id: string, position: int, lo: float, target: string, facets: ?list<string>}> */
    public array $disputes = [];

    /** @var list<array{target: string, entity: string}> */
    public array $rejectedPairs = [];

    /** @var array<string, array<string, true>> type => [id => true] for entities that were split */
    public array $splitParents = [];

    /** @var array<string, array<string, array{value: array, claim: string}>> type => [id => latest effective definition] */
    public array $definitions = [];

    /** @var array<string, list<string>> misconception id => ids of every claim that defined it */
    public array $misconceptionDefiners = [];

    /** @var array<string, array<string, true>> topic => its direct parents */
    public array $parents = [];

    /** @var array<string, list<string>> task => the topics it exercises */
    public array $exercises = [];

    /** @var array<string, true> */
    public array $examRelevant = [];

    /** @var array<string, list<string>> question id => the refs it stems from */
    public array $stemsFrom = [];

    /** @var array<string, list<string>> exposure id => the refs it addresses */
    public array $addresses = [];

    /** @var array<string, array> */
    public array $attempts = [];

    /** @var array<string, array> */
    public array $exposures = [];

    /** @var array<string, array> */
    public array $selfReports = [];

    /** @var array<string, array> */
    public array $asks = [];

    /** @var array<string, array<string, string>> type => [id => survivor id] */
    private array $sameAs = [];

    /** @var array<string, array<string, array<string, string>>> type => [id => [ref => new id]] */
    private array $splitAssignments = [];

    /** @var array<string, list<JournalEntry>> judged event id => effective judges claims */
    private array $judges = [];

    /** @var array<string, list<string>> */
    private array $ancestorCache = [];

    /** @var array<string, list<JournalEntry>> */
    private array $effectiveCache = [];

    /** @param list<JournalEntry> $entries */
    public function __construct(array $entries, private ProjectionOptions $options, private Rules $rules)
    {
        usort($entries, fn (JournalEntry $a, JournalEntry $b) => $a->position <=> $b->position);

        foreach ($entries as $entry) {
            if ($options->maxPosition !== null && $entry->position > $options->maxPosition) {
                continue;
            }
            if ($options->observedUntil !== null && $entry->kind->isObservation()
                && $entry->interval->lo > Interval::epoch($options->observedUntil)) {
                continue;
            }
            $this->entries[$entry->id] = $entry;
            $this->bodies[$entry->id] = $entry->body;
        }

        $this->applyAmendments();
        $this->resolveClaims();
        $this->indexIdentity();
        $this->indexRelations();
        $this->indexObservations();
    }

    public function maxPosition(): int
    {
        $positions = array_map(fn (JournalEntry $e) => $e->position, $this->entries);

        return $positions === [] ? 0 : max($positions);
    }

    public function redirect(string $type, string $id): string
    {
        $seen = [];
        while (isset($this->sameAs[$type][$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $id = $this->sameAs[$type][$id];
        }

        return $id;
    }

    public function isMergedAway(string $type, string $id): bool
    {
        return $this->redirect($type, $id) !== $id;
    }

    /** @return list<string> the topic's ancestors through part_of */
    public function ancestors(string $topic): array
    {
        if (isset($this->ancestorCache[$topic])) {
            return $this->ancestorCache[$topic];
        }

        $found = [];
        $queue = array_keys($this->parents[$topic] ?? []);
        while ($queue !== []) {
            $parent = array_shift($queue);
            if (isset($found[$parent]) || $parent === $topic) {
                continue;
            }
            $found[$parent] = true;
            array_push($queue, ...array_keys($this->parents[$parent] ?? []));
        }

        return $this->ancestorCache[$topic] = array_keys($found);
    }

    /** @return list<string> topics whose parent (through part_of) is $topic */
    public function children(string $topic): array
    {
        return array_keys(array_filter($this->parents, fn (array $parents) => isset($parents[$topic])));
    }

    public function teaches(array $exposure, string $topic): bool
    {
        foreach ($exposure['about'] as $about) {
            if ($about === $topic || in_array($about, $this->ancestors($topic), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> a question's topics: what it stems from, plus the topics of misconceptions it stems from */
    public function questionTopics(string $question): array
    {
        $topics = [];
        foreach ($this->stemsFrom[$question] ?? [] as $ref) {
            [$type, $id] = Ref::parse($ref);
            if ($type === 'topic') {
                $topics[] = $id;
            } elseif ($type === 'misconception') {
                array_push($topics, ...$this->misconceptionTopics($id));
            }
        }

        return array_values(array_unique($topics));
    }

    /** @return list<string> */
    public function misconceptionTopics(string $misconception): array
    {
        $value = $this->definitions['misconception'][$misconception]['value'] ?? [];

        return array_values(array_unique(array_map(
            fn (string $ref) => $this->redirect('topic', Ref::id($ref)),
            $value['topics'] ?? [],
        )));
    }

    private function applyAmendments(): void
    {
        foreach ($this->entries as $entry) {
            if ($entry->kind !== Kind::Amendment) {
                continue;
            }
            foreach ($entry->body['targets'] as $ref) {
                [$type, $id] = Ref::parse($ref);
                if ($type !== 'event' || ! isset($this->entries[$id]) || $this->entries[$id]->kind === Kind::Claim) {
                    continue;
                }
                match ($entry->body['action']) {
                    'retract' => $this->retracted[$id] = true,
                    'amend' => $this->bodies[$id] = array_replace_recursive($this->bodies[$id], $entry->body['replacement'] ?? []),
                    'redact' => null,
                };
            }
        }
    }

    private function resolveClaims(): void
    {
        $reviews = [];
        foreach ($this->entries as $id => $entry) {
            if ($entry->kind !== Kind::Claim) {
                continue;
            }
            $this->status[$id] = $entry->body['review']['state'];
            if ($entry->claimType() === 'reviews' && $this->status[$id] === 'accepted') {
                $reviews[] = $entry;
            }
        }

        $valid = array_filter($reviews, fn (JournalEntry $review) => $this->reviewIsAllowed($review));

        $withdrawn = [];
        foreach ($valid as $review) {
            if ($review->body['value']['decision'] === 'withdraw') {
                $withdrawn[Ref::id($review->body['targets'][0])] = true;
            }
        }

        foreach ($valid as $review) {
            if (isset($withdrawn[$review->id])) {
                continue;
            }
            $decision = $review->body['value']['decision'];
            $target = $review->body['targets'][0];

            if ($decision === 'accept' || $decision === 'reject') {
                $this->status[Ref::id($target)] = $decision === 'accept' ? 'accepted' : 'rejected';
            } elseif ($decision === 'dispute' && ! $this->options->ignoreDisputes) {
                $this->disputes[] = [
                    'id' => $review->id,
                    'position' => $review->position,
                    'lo' => $review->interval->lo,
                    'target' => $target,
                    'facets' => $review->body['value']['facets'] ?? null,
                ];
            }
        }
    }

    private function reviewIsAllowed(JournalEntry $review): bool
    {
        [$type, $id] = Ref::parse($review->body['targets'][0]);
        $target = in_array($type, ['claim', 'event'], true) ? ($this->entries[$id] ?? null) : null;
        $judged = null;
        if ($target?->claimType() === 'judges') {
            $judged = $this->entries[Ref::id($target->body['targets'][0])] ?? null;
        }

        return ReviewPolicy::violation($review, $target, $judged) === null;
    }

    public function isEffective(string $claimId): bool
    {
        return ($this->status[$claimId] ?? null) === 'accepted';
    }

    /** @return list<JournalEntry> effective claims of one type, in position order */
    private function effective(string $type): array
    {
        return $this->effectiveCache[$type] ??= array_values(array_filter(
            $this->entries,
            fn (JournalEntry $e) => $e->claimType() === $type && $this->isEffective($e->id),
        ));
    }

    private function indexIdentity(): void
    {
        foreach ($this->entries as $entry) {
            if ($entry->claimType() === 'defines' && ($entry->body['value']['entity_type'] ?? null) === 'misconception') {
                $this->misconceptionDefiners[Ref::id($entry->body['targets'][0])][] = $entry->id;
            }
            if (in_array($entry->claimType(), ['refers_to', 'same_as'], true) && $this->status[$entry->id] === 'rejected') {
                $this->rejectedPairs[] = $entry->claimType() === 'refers_to'
                    ? ['target' => $entry->body['targets'][0], 'entity' => $entry->body['value']['entity']]
                    : ['target' => $entry->body['targets'][0], 'entity' => $entry->body['targets'][1]];
            }
        }

        foreach ($this->effective('same_as') as $claim) {
            [$type, $a] = Ref::parse($claim->body['targets'][0]);
            $b = Ref::id($claim->body['value']['survivor'] ?? $claim->body['targets'][1]);
            if ($a !== $b) {
                $this->sameAs[$type][$a] = $b;
            }
        }

        foreach ($this->effective('splits_into') as $claim) {
            [$type, $a] = Ref::parse($claim->body['targets'][0]);
            $this->splitParents[$type][$a] = true;
            foreach ($claim->body['value']['assignments'] ?? [] as $assignment) {
                $this->splitAssignments[$type][$a][$assignment['ref']] = Ref::id($assignment['to']);
            }
        }

        foreach ($this->effective('defines') as $claim) {
            $type = $claim->body['value']['entity_type'];
            $id = Ref::id($claim->body['targets'][0]);
            $this->definitions[$type][$id] = ['value' => $claim->body['value'], 'claim' => $claim->id];
        }
    }

    private function indexRelations(): void
    {
        $latest = [];
        foreach ($this->effective('relates') as $claim) {
            [$from, $to] = $claim->body['targets'];
            $latest[$from.'|'.$claim->body['value']['relation'].'|'.$to] = $claim;
        }

        foreach ($latest as $claim) {
            if (($claim->body['value']['status'] ?? 'active') !== 'active') {
                continue;
            }
            [$fromType, $fromId] = Ref::parse($claim->body['targets'][0]);
            [$toType, $toId] = Ref::parse($claim->body['targets'][1]);
            $to = $this->redirect($toType, $toId);

            switch ($claim->body['value']['relation']) {
                case 'part_of':
                    $this->parents[$this->redirect('topic', $fromId)][$to] = true;
                    break;
                case 'exercises':
                    $this->exercises[$this->redirect('task', $fromId)][] = $to;
                    break;
                case 'emphasizes':
                    if (($claim->body['value']['qualifiers']['emphasis'] ?? null) === 'exam_relevant') {
                        $this->examRelevant[$to] = true;
                    }
                    break;
                case 'stems_from':
                    $this->stemsFrom[$this->redirect('question', $fromId)][] = Ref::make($toType, $to);
                    break;
                case 'addresses':
                    $this->addresses[$fromId][] = Ref::make($toType, $to);
                    break;
            }
        }

        foreach ($this->exercises as $task => $topics) {
            $this->exercises[$task] = array_values(array_unique($topics));
        }

        foreach ($this->effective('judges') as $claim) {
            $this->judges[Ref::id($claim->body['targets'][0])][] = $claim;
        }
    }

    private function indexObservations(): void
    {
        $timeline = array_filter(
            $this->entries,
            fn (JournalEntry $e) => $e->kind->isObservation() && ! isset($this->retracted[$e->id]),
        );
        usort($timeline, fn (JournalEntry $a, JournalEntry $b) => self::compare($a->sortKey(), $b->sortKey()));

        foreach ($timeline as $entry) {
            match ($entry->kind) {
                Kind::Exposure => $this->exposures[$entry->id] = $this->exposureFact($entry),
                Kind::SelfReport => $this->selfReports[$entry->id] = $this->selfReportFact($entry),
                Kind::Question => $this->asks[$entry->id] = $this->askFact($entry),
                default => null,
            };
        }

        foreach ($timeline as $entry) {
            if ($entry->kind === Kind::Attempt) {
                $this->attempts[$entry->id] = $this->attemptFact($entry);
            }
        }

        $this->classifyRepeats();
    }

    private function exposureFact(JournalEntry $entry): array
    {
        $body = $this->bodies[$entry->id];
        $answers = [];
        $effects = [];
        foreach ($this->judges[$entry->id] ?? [] as $claim) {
            $rank = Authority::ofMethod($claim->body['method']['kind']);
            foreach ($claim->body['value']['answer'] ?? [] as $answer) {
                $question = $this->redirect('question', Ref::id($answer['question']));
                $answers[$question][] = ['value' => $answer['adequacy'], 'rank' => $rank, 'position' => $claim->position];
            }
            foreach ($claim->body['value']['effect'] ?? [] as $effect) {
                [$type, $id] = Ref::parse($effect['target']);
                $effects[Ref::make($type, $this->redirect($type, $id))][] = ['value' => $effect['effect'], 'rank' => $rank, 'position' => $claim->position];
            }
        }

        return [
            'id' => $entry->id,
            'key' => $entry->sortKey(),
            'interval' => $entry->interval,
            'session' => $entry->sessionId,
            'about' => $this->aboutIds($entry, 'topic'),
            'responds_to' => array_map(fn ($ref) => Ref::id($ref), $entry->linkTargets('responds_to')),
            'approach' => $body['approach'] ?? [],
            'answers' => array_map(fn (array $v) => self::best($v)['value'], $answers),
            'effects' => array_map(fn (array $v) => self::best($v)['value'], $effects),
        ];
    }

    private function selfReportFact(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'key' => $entry->sortKey(),
            'interval' => $entry->interval,
            'stance' => $this->bodies[$entry->id]['stance'],
            'topics' => $this->aboutIds($entry, 'topic'),
            'questions' => $this->aboutIds($entry, 'question'),
        ];
    }

    private function askFact(JournalEntry $entry): array
    {
        $questions = [];
        foreach ($this->effective('refers_to') as $claim) {
            if ($claim->body['targets'][0] !== Ref::make('event', $entry->id)) {
                continue;
            }
            [$type, $id] = Ref::parse($claim->body['value']['entity']);
            if ($type !== 'question') {
                continue;
            }
            $id = $this->splitAssignments['question'][$id][Ref::make('event', $entry->id)] ?? $id;
            $questions[] = $this->redirect('question', $id);
        }

        return [
            'id' => $entry->id,
            'key' => $entry->sortKey(),
            'interval' => $entry->interval,
            'questions' => array_values(array_unique($questions)),
        ];
    }

    /** @return list<string> */
    private function aboutIds(JournalEntry $entry, string $type): array
    {
        $ids = [];
        foreach ($entry->linkTargets('about') as $ref) {
            [$refType, $id] = Ref::parse($ref);
            if ($refType === $type) {
                $ids[] = $this->redirect($type, $id);
            }
        }

        return array_values(array_unique($ids));
    }

    private function attemptFact(JournalEntry $entry): array
    {
        $body = $this->bodies[$entry->id];
        $task = $this->redirect('task', $body['task']);
        $taskTopics = $this->exercises[$task] ?? [];

        $verdicts = [];
        $claimFacets = [];
        $captureRank = Authority::ofJudgedBy($body['judged_by']);
        if ($captureRank !== null && in_array($body['outcome'], ['correct', 'partial', 'incorrect'], true)) {
            $verdicts['overall'][] = ['value' => $body['outcome'], 'rank' => $captureRank, 'position' => $entry->position, 'claim' => null, 'derived' => [], 'supersedes' => []];
        }

        foreach ($this->judges[$entry->id] ?? [] as $claim) {
            $claimFacets[$claim->id] = [];
            $base = [
                'rank' => Authority::ofMethod($claim->body['method']['kind']),
                'position' => $claim->position,
                'claim' => $claim->id,
                'derived' => $claim->body['derived_from'] ?? [],
                'supersedes' => array_map(fn ($ref) => Ref::id($ref), $claim->body['supersedes'] ?? []),
            ];
            foreach ($this->expandFacets($claim->body['value']) as [$group, $key, $value]) {
                $verdicts[$key][] = ['value' => $value] + $base;
                $claimFacets[$claim->id][$group][] = $key;
            }
        }

        $covering = $this->disputesFor($entry->id, $claimFacets);

        $resolve = function (string $key) use ($verdicts, $covering) {
            $candidates = $verdicts[$key] ?? [];
            if ($this->options->humanJudgedOnly && ($key === 'overall' || str_starts_with($key, 'topic:'))) {
                $candidates = array_values(array_filter($candidates, fn ($v) => Authority::isHuman($v['rank'])));
            }

            return self::resolveFacet($candidates, $covering[$key] ?? []);
        };

        $overall = $resolve('overall');

        $topics = [];
        $named = [];
        foreach (array_keys($verdicts) as $key) {
            if (str_starts_with($key, 'topic:')) {
                $named[] = substr($key, 6);
            }
        }
        $specific = $this->mostSpecific($taskTopics);
        foreach (array_values(array_unique([...$taskTopics, ...$named])) as $topic) {
            $value = $resolve('topic:'.$topic);
            if ($value === null && in_array($topic, $taskTopics, true)) {
                $value = match (true) {
                    $overall === self::DISPUTED => self::DISPUTED,
                    $overall === null => null,
                    $overall['value'] === 'correct' => $overall,
                    in_array($topic, $specific, true) => $overall,
                    default => null,
                };
            }
            $topics[$topic] = $value;
        }

        $misconceptions = [];
        $demonstrates = [];
        foreach (array_keys($verdicts) as $key) {
            if (str_starts_with($key, 'misc:')) {
                $value = $resolve($key);
                if ($value !== null) {
                    $misconceptions[substr($key, 5)] = $value === self::DISPUTED ? self::DISPUTED : $value['value'];
                }
            } elseif (str_starts_with($key, 'demo:')) {
                $value = $resolve($key);
                if (is_array($value) && $value['value'] === true) {
                    $demonstrates[substr($key, 5)] = true;
                }
            }
        }

        $support = $resolve('support');
        $ownWords = $resolve('own_words');
        $cause = $resolve('cause');
        $interpreterDisagrees = false;
        foreach ($verdicts['overall'] ?? [] as $a) {
            foreach ($verdicts['overall'] as $b) {
                if ($a['rank'] === Authority::AUTO && $b['rank'] === Authority::INTERPRETER && $a['value'] !== $b['value']) {
                    $interpreterDisagrees = true;
                }
            }
        }

        return [
            'id' => $entry->id,
            'key' => $entry->sortKey(),
            'interval' => $entry->interval,
            'session' => $entry->sessionId,
            'task' => $task,
            'form' => $body['form'],
            'support' => match (true) {
                $support === self::DISPUTED => self::DISPUTED,
                is_array($support) => $support['value'],
                default => $body['support'],
            },
            'overall' => $overall,
            'topics' => $topics,
            'own_words' => is_array($ownWords) ? $ownWords['value'] : $ownWords,
            'misc' => $misconceptions,
            'demo' => $demonstrates,
            'cause' => is_array($cause) ? $cause['value'] : null,
            'checker_suspect' => $interpreterDisagrees,
            'repeat' => 'none',
        ];
    }

    /** @return list<array{0: string, 1: string, 2: mixed}> group, facet key, value */
    private function expandFacets(array $value): array
    {
        $facets = [];
        if (isset($value['overall'])) {
            $facets[] = ['overall', 'overall', $value['overall']];
        }
        foreach ($value['topics'] ?? [] as $topic) {
            $facets[] = ['topics', 'topic:'.$this->redirect('topic', Ref::id($topic['topic'])), $topic['outcome']];
        }
        if (isset($value['support'])) {
            $facets[] = ['support', 'support', $value['support']];
        }
        if (array_key_exists('own_words', $value)) {
            $facets[] = ['own_words', 'own_words', (bool) $value['own_words']];
        }
        foreach ($value['misconceptions'] ?? [] as $misconception) {
            $facets[] = ['misconceptions', 'misc:'.$this->redirect('misconception', Ref::id($misconception['misconception'])), (bool) $misconception['present']];
        }
        foreach ($value['demonstrates'] ?? [] as $question) {
            $facets[] = ['demonstrates', 'demo:'.$this->redirect('question', Ref::id($question)), true];
        }
        if (isset($value['cause'])) {
            $facets[] = ['cause', 'cause', $value['cause']];
        }

        return $facets;
    }

    /**
     * Disputes covering each facet of one attempt, whether they target the
     * attempt itself (its recorded outcome) or one of the judges claims on it.
     *
     * @param  array<string, array<string, list<string>>>  $claimFacets  claim id => group => facet keys
     * @return array<string, list<array>> facet key => covering disputes
     */
    private function disputesFor(string $attemptId, array $claimFacets): array
    {
        $covering = [];
        foreach ($this->disputes as $dispute) {
            [$type, $id] = Ref::parse($dispute['target']);
            if ($type === 'event' && $id === $attemptId) {
                // Disputing the attempt itself contests its recorded outcome.
                $covering['overall'][] = $dispute;
            } elseif ($type === 'claim' && isset($claimFacets[$id])) {
                $groups = $dispute['facets'] ?? array_keys($claimFacets[$id]);
                foreach ($groups as $group) {
                    foreach ($claimFacets[$id][$group] ?? [] as $key) {
                        $covering[$key][] = $dispute;
                    }
                }
            }
        }

        return $covering;
    }

    /**
     * ADR 0002 §6: a covering dispute suspends the facet unless an adjudication
     * that cites it, recorded after it, with enough authority, settles it.
     *
     * @return array{value: mixed, rank: int, position: int}|string|null
     */
    private static function resolveFacet(array $candidates, array $covering): array|string|null
    {
        if ($covering !== []) {
            usort($covering, fn ($a, $b) => $b['position'] <=> $a['position']);
            $dispute = $covering[0];
            $before = array_filter($candidates, fn ($v) => $v['position'] < $dispute['position']);
            $threshold = $before === [] ? 0 : max(array_column($before, 'rank'));
            $adjudications = array_values(array_filter($candidates, fn ($v) => $v['position'] > $dispute['position']
                && in_array('claim:'.$dispute['id'], $v['derived'], true)
                && $v['rank'] >= $threshold));

            return $adjudications === [] ? self::DISPUTED : self::best($adjudications);
        }

        if ($candidates === []) {
            return null;
        }

        $superseded = array_merge(...array_map(fn ($v) => $v['supersedes'] ?? [], $candidates));
        $live = array_values(array_filter($candidates, fn ($v) => ! in_array($v['claim'] ?? null, $superseded, true)));

        return self::best($live === [] ? $candidates : $live);
    }

    /** Highest authority, then the latest position. */
    private static function best(array $candidates): array
    {
        usort($candidates, fn ($a, $b) => [$b['rank'], $b['position']] <=> [$a['rank'], $a['position']]);

        return $candidates[0];
    }

    /** @return list<string> the task topics that have no sub-topic among the task's topics */
    private function mostSpecific(array $topics): array
    {
        return array_values(array_filter($topics, function (string $topic) use ($topics) {
            foreach ($topics as $other) {
                if ($other !== $topic && in_array($topic, $this->ancestors($other), true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** ADR 0002 §7: a repeat is delayed only if it's in another session, a day later, with no teaching in that day. */
    private function classifyRepeats(): void
    {
        $previousByTask = [];
        foreach ($this->attempts as $id => $attempt) {
            $previous = $previousByTask[$attempt['task']] ?? null;
            $previousByTask[$attempt['task']] = $attempt;
            if ($previous === null) {
                continue;
            }

            $delayed = $attempt['session'] !== null
                && $attempt['session'] !== $previous['session']
                && Interval::guaranteedGap($previous['interval'], $attempt['interval']) >= $this->rules->retryDelay
                && ! $this->teachingWithin($attempt, $this->exercises[$attempt['task']] ?? []);

            $this->attempts[$id]['repeat'] = $delayed ? 'delayed' : 'immediate';
        }
    }

    private function teachingWithin(array $attempt, array $topics): bool
    {
        foreach ($this->exposures as $exposure) {
            if ($exposure['interval']->lo > $attempt['interval']->hi) {
                continue;
            }
            foreach ($topics as $topic) {
                if ($this->teaches($exposure, $topic)
                    && $attempt['interval']->lo - $exposure['interval']->hi < $this->rules->retryDelay) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function compare(array $a, array $b): int
    {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    }
}
