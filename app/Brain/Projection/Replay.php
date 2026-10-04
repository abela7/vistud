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

    /** @var array<string, array<string, true>> type => [survivor id => true] for entities others were merged into */
    public array $mergedInto = [];

    /** @var array<string, array<string, true>> record type => [record id => true] */
    private array $recordEntities = [];

    /** @var array<string, int> claim id => position from which it has been accepted */
    private array $effectiveSince = [];

    /**
     * Splits that could not validly apply, so the previous interpretation
     * stays in force (ADR 0002 §5, splits_into).
     *
     * @var list<array{claim: string, entity: string, problems: list<string>, unassigned: list<string>}>
     */
    public array $invalidSplits = [];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> exposure id, target type, target id, claim id */
    private array $addressLinks = [];

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
        $this->applySplits();
        $this->indexAddresses();
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

    /**
     * The entity a piece of evidence counts towards: merges are followed,
     * and when the entity was split, the evidence goes to the entity its
     * assignment names (ADR 0002 §5, splits_into). Evidence is identified by
     * references, most specific first, for example the verdict claim and
     * then the attempt it judges. Unassigned evidence stays with the entity.
     *
     * @param  list<string>  $evidence
     */
    public function entity(string $type, string $id, array $evidence): string
    {
        $id = $this->redirect($type, $id);
        for ($depth = 0; $depth < 8 && isset($this->splitAssignments[$type][$id]); $depth++) {
            $to = null;
            foreach ($evidence as $ref) {
                if (isset($this->splitAssignments[$type][$id][$ref])) {
                    $to = $this->splitAssignments[$type][$id][$ref];
                    break;
                }
            }
            if ($to === null) {
                break;
            }
            $id = $this->redirect($type, $to);
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

    /** @return list<string> topics that are part of $topic, directly or through sub-topics */
    public function descendants(string $topic): array
    {
        return array_keys(array_filter($this->parents, fn (array $parents, string $child) => $child !== $topic
            && in_array($topic, $this->ancestors($child), true), ARRAY_FILTER_USE_BOTH));
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
            if ($this->status[$id] === 'accepted') {
                $this->effectiveSince[$id] = $entry->position;
            }
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
                if ($decision === 'accept') {
                    $this->effectiveSince[Ref::id($target)] = $review->position;
                } else {
                    unset($this->effectiveSince[Ref::id($target)]);
                }
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
            [$type, $first] = Ref::parse($claim->body['targets'][0]);
            $second = Ref::id($claim->body['targets'][1]);
            // ADR 0002 §5: the survivor keeps its identity; the other one's evidence counts as the survivor's.
            $survivor = Ref::id($claim->body['value']['survivor'] ?? $claim->body['targets'][1]);
            $merged = $survivor === $first ? $second : $first;
            if ($merged !== $survivor) {
                $this->sameAs[$type][$merged] = $survivor;
                $this->mergedInto[$type][$survivor] = true;
            }
        }

        foreach ($this->effective('defines') as $claim) {
            $type = $claim->body['value']['entity_type'];
            $id = Ref::id($claim->body['targets'][0]);
            $this->definitions[$type][$id] = ['value' => $claim->body['value'], 'claim' => $claim->id];
        }

        // Tasks and other records introduce entities too (ADR 0002 §4).
        foreach ($this->entries as $entry) {
            if ($entry->kind === Kind::Record && ! isset($this->retracted[$entry->id])) {
                $this->recordEntities[$this->bodies[$entry->id]['record_type']][$this->bodies[$entry->id]['record_id']] = true;
            }
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
                    $this->addressLinks[] = [$fromId, $toType, $toId, $claim->id];
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

    /**
     * ADR 0002 §5, splits_into, as clarified in the WP4 review: a split
     * applies only if it is complete when it takes effect. Splits are taken
     * in the order they became effective, so a later split sees the evidence
     * an earlier one assigned. A split that cannot validly apply is recorded
     * in $invalidSplits and changes nothing.
     */
    private function applySplits(): void
    {
        $claims = $this->effective('splits_into');
        usort($claims, fn (JournalEntry $a, JournalEntry $b) => [$this->effectiveSince[$a->id], $a->position] <=> [$this->effectiveSince[$b->id], $b->position]);

        foreach ($claims as $claim) {
            $problems = $this->splitProblems($claim);
            if ($problems['problems'] !== []) {
                $this->invalidSplits[] = ['claim' => $claim->id] + $problems;

                continue;
            }
            [$type, $id] = Ref::parse($claim->body['targets'][0]);
            $id = $this->redirect($type, $id);
            $this->splitParents[$type][$id] = true;
            foreach ($claim->body['value']['assignments'] as $assignment) {
                $this->splitAssignments[$type][$id][$assignment['ref']] = Ref::id($assignment['to']);
            }
        }
    }

    /**
     * Why a split cannot apply. Problems:
     * - `no_parts`: `into` is empty;
     * - `part_not_defined`: a part is not an effective definition of the same type, or is the split entity itself;
     * - `assignment_outside_parts`: an assignment names an entity that is not one of the parts;
     * - `assignment_not_evidence`: an assignment names something that is not the split entity's evidence;
     * - `unassigned_evidence`: a piece of the entity's evidence, recorded before the split took effect, has no assignment.
     *
     * @return array{entity: string, problems: list<string>, unassigned: list<string>}
     */
    public function splitProblems(JournalEntry $claim): array
    {
        [$type, $id] = Ref::parse($claim->body['targets'][0]);
        $id = $this->redirect($type, $id);
        $value = $claim->body['value'];
        $problems = [];

        $parts = [];
        foreach ($value['into'] ?? [] as $ref) {
            [$partType, $partId] = Ref::parse($ref);
            $partId = $this->redirect($partType, $partId);
            $known = isset($this->definitions[$type][$partId]) || isset($this->recordEntities[$type][$partId]);
            if ($partType !== $type || $partId === $id || ! $known) {
                $problems[] = 'part_not_defined';
            }
            $parts[] = $partId;
        }
        if ($parts === []) {
            $problems[] = 'no_parts';
        }

        $assigned = [];
        foreach ($value['assignments'] ?? [] as $assignment) {
            if (! in_array($this->redirect($type, Ref::id($assignment['to'])), $parts, true)) {
                $problems[] = 'assignment_outside_parts';
            }
            $assigned[$assignment['ref']] = true;
        }

        $evidence = $this->evidenceOf($type, $id, $this->effectiveSince[$claim->id] ?? $claim->position);
        $unassigned = [];
        $covered = [];
        foreach ($evidence as $refs) {
            $hits = array_filter($refs, fn (string $ref) => isset($assigned[$ref]));
            if ($hits === []) {
                $unassigned[] = end($refs);
            }
            foreach ($refs as $ref) {
                $covered[$ref] = true;
            }
        }
        if ($unassigned !== []) {
            $problems[] = 'unassigned_evidence';
        }
        if (array_diff_key($assigned, $covered) !== []) {
            $problems[] = 'assignment_not_evidence';
        }

        $problems = array_values(array_unique($problems));
        sort($problems);
        $unassigned = array_values(array_unique($unassigned));
        sort($unassigned);

        return ['entity' => Ref::make($type, $id), 'problems' => $problems, 'unassigned' => $unassigned];
    }

    /**
     * The evidence of an entity recorded before a position, as it stands
     * with the merges and splits already in force. Each piece is identified
     * by its references, most specific first: a verdict or match claim, then
     * the event it is about. An assignment to any of them covers the piece.
     *
     * @return list<list<string>>
     */
    private function evidenceOf(string $type, string $id, int $before): array
    {
        $items = [];
        $is = fn (string $refType, string $refId, array $evidence) => $refType === $type && $this->entity($type, $refId, $evidence) === $id;

        foreach ($this->entries as $entry) {
            if ($entry->position >= $before) {
                continue;
            }
            $event = 'event:'.$entry->id;

            if ($entry->kind->isObservation()) {
                if (isset($this->retracted[$entry->id])) {
                    continue;
                }
                if ($entry->kind === Kind::Attempt) {
                    $task = $this->entity('task', $this->bodies[$entry->id]['task'], [$event]);
                    $onTopic = array_filter($this->exercises[$task] ?? [], fn ($t) => $is('topic', $t, [$event]));
                    if (($type === 'task' && $task === $id) || $onTopic !== []) {
                        $items[] = [$event];
                    }
                }
                if ($entry->kind === Kind::Exposure || $entry->kind === Kind::SelfReport) {
                    foreach ($entry->linkTargets('about') as $ref) {
                        [$refType, $refId] = Ref::parse($ref);
                        if ($is($refType, $refId, [$event])) {
                            $items[] = [$event];
                            break;
                        }
                    }
                }

                continue;
            }

            if (! $this->isEffective($entry->id)) {
                continue;
            }
            $claim = 'claim:'.$entry->id;
            $value = $entry->body['value'] ?? [];
            // A mention reference ("mention:<event>/<n>") is about its event.
            $about = 'event:'.explode('/', Ref::id($entry->body['targets'][0]))[0];
            $refs = [$claim, $about];

            if ($entry->claimType() === 'judges') {
                $named = [];
                foreach ($value['topics'] ?? [] as $t) {
                    $named[] = $t['topic'];
                }
                foreach ($value['misconceptions'] ?? [] as $m) {
                    $named[] = $m['misconception'];
                }
                foreach ($value['demonstrates'] ?? [] as $q) {
                    $named[] = $q;
                }
                foreach ($value['answer'] ?? [] as $a) {
                    $named[] = $a['question'];
                }
                foreach ($value['effect'] ?? [] as $e) {
                    $named[] = $e['target'];
                }
                foreach ($named as $ref) {
                    [$refType, $refId] = Ref::parse($ref);
                    if ($is($refType, $refId, $refs)) {
                        $items[] = $refs;
                        break;
                    }
                }
            } elseif ($entry->claimType() === 'refers_to' || ($entry->claimType() === 'relates' && ($value['relation'] ?? null) === 'addresses')) {
                $target = $entry->claimType() === 'refers_to' ? $value['entity'] : $entry->body['targets'][1];
                [$refType, $refId] = Ref::parse($target);
                if ($is($refType, $refId, $refs)) {
                    $items[] = $refs;
                }
            }
        }

        return $items;
    }

    private function indexAddresses(): void
    {
        foreach ($this->addressLinks as [$exposure, $type, $id, $claim]) {
            $this->addresses[$exposure][] = Ref::make($type, $this->entity($type, $id, ['claim:'.$claim, 'event:'.$exposure]));
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
            $evidence = ['claim:'.$claim->id, 'event:'.$entry->id];
            foreach ($claim->body['value']['answer'] ?? [] as $answer) {
                $question = $this->entity('question', Ref::id($answer['question']), $evidence);
                $answers[$question][] = ['value' => $answer['adequacy'], 'rank' => $rank, 'position' => $claim->position];
            }
            foreach ($claim->body['value']['effect'] ?? [] as $effect) {
                [$type, $id] = Ref::parse($effect['target']);
                $effects[Ref::make($type, $this->entity($type, $id, $evidence))][] = ['value' => $effect['effect'], 'rank' => $rank, 'position' => $claim->position];
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
            $questions[] = $this->entity('question', $id, ['claim:'.$claim->id, 'event:'.$entry->id]);
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
                $ids[] = $this->entity($type, $id, ['event:'.$entry->id]);
            }
        }

        return array_values(array_unique($ids));
    }

    private function attemptFact(JournalEntry $entry): array
    {
        $body = $this->bodies[$entry->id];
        $evidence = ['event:'.$entry->id];
        $task = $this->entity('task', $body['task'], $evidence);
        $taskTopics = array_values(array_unique(array_map(
            fn (string $topic) => $this->entity('topic', $topic, $evidence),
            $this->exercises[$task] ?? [],
        )));

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
            foreach ($this->expandFacets($claim->body['value'], ['claim:'.$claim->id, 'event:'.$entry->id]) as [$group, $key, $value]) {
                $verdicts[$key][] = ['value' => $value] + $base;
                $claimFacets[$claim->id][$group][] = $key;
            }
        }

        $covering = $this->disputesFor($entry->id, $claimFacets);

        // ADR 0002 §6: a lower-authority verdict that disagrees is kept and
        // shown as overridden.
        $overridden = [];
        $resolve = function (string $key) use ($verdicts, $covering, &$overridden) {
            $candidates = $verdicts[$key] ?? [];
            if ($this->options->humanJudgedOnly && ($key === 'overall' || str_starts_with($key, 'topic:'))) {
                $candidates = array_values(array_filter($candidates, fn ($v) => Authority::isHuman($v['rank'])));
            }

            $effective = self::resolveFacet($candidates, $covering[$key] ?? []);
            if (is_array($effective)) {
                foreach ($candidates as $candidate) {
                    if ($candidate['claim'] !== null && $candidate['value'] !== $effective['value'] && $candidate['rank'] < $effective['rank']) {
                        $overridden[$candidate['claim']] = true;
                    }
                }
            }

            return $effective;
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
            'overridden' => self::sortedKeys($overridden),
            'repeat' => 'none',
        ];
    }

    /** @return list<array{0: string, 1: string, 2: mixed}> group, facet key, value */
    private function expandFacets(array $value, array $evidence): array
    {
        $facets = [];
        if (isset($value['overall'])) {
            $facets[] = ['overall', 'overall', $value['overall']];
        }
        foreach ($value['topics'] ?? [] as $topic) {
            $facets[] = ['topics', 'topic:'.$this->entity('topic', Ref::id($topic['topic']), $evidence), $topic['outcome']];
        }
        if (isset($value['support'])) {
            $facets[] = ['support', 'support', $value['support']];
        }
        if (array_key_exists('own_words', $value)) {
            $facets[] = ['own_words', 'own_words', (bool) $value['own_words']];
        }
        foreach ($value['misconceptions'] ?? [] as $misconception) {
            $facets[] = ['misconceptions', 'misc:'.$this->entity('misconception', Ref::id($misconception['misconception']), $evidence), (bool) $misconception['present']];
        }
        foreach ($value['demonstrates'] ?? [] as $question) {
            $facets[] = ['demonstrates', 'demo:'.$this->entity('question', Ref::id($question), $evidence), true];
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

            return $adjudications === [] ? self::DISPUTED : self::precedence($adjudications);
        }

        return $candidates === [] ? null : self::precedence($candidates);
    }

    /**
     * ADR 0002 §6 step 3: the highest authority wins. Among verdicts of equal
     * authority, one that explicitly supersedes another wins; failing that,
     * the latest position. A lower-authority verdict can never supersede a
     * higher one.
     */
    private static function precedence(array $candidates): array
    {
        $top = max(array_column($candidates, 'rank'));
        $tier = array_values(array_filter($candidates, fn ($v) => $v['rank'] === $top));
        $superseded = array_merge(...array_map(fn ($v) => $v['supersedes'] ?? [], $tier));
        $live = array_values(array_filter($tier, fn ($v) => ! in_array($v['claim'] ?? null, $superseded, true)));

        return self::best($live === [] ? $tier : $live);
    }

    /** Highest authority, then the latest position. */
    private static function best(array $candidates): array
    {
        usort($candidates, fn ($a, $b) => [$b['rank'], $b['position']] <=> [$a['rank'], $a['position']]);

        return $candidates[0];
    }

    /**
     * The task topics that have no sub-topic among the task's topics. If
     * part_of relations form a cycle, every topic in it has a sub-topic;
     * then the failure falls on all the task's topics rather than on none,
     * so a bad relation can never hide a failure.
     *
     * @return list<string>
     */
    private function mostSpecific(array $topics): array
    {
        $specific = $this->leaves($topics);

        return $specific === [] ? $topics : $specific;
    }

    /** @return list<string> */
    private function leaves(array $topics): array
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

    /** @return list<string> */
    private static function sortedKeys(array $set): array
    {
        $keys = array_map('strval', array_keys($set));
        sort($keys);

        return $keys;
    }

    public static function compare(array $a, array $b): int
    {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    }
}
