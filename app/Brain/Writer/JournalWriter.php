<?php

namespace App\Brain\Writer;

use App\Brain\Journal\EntryFactory;
use App\Brain\Journal\EntryValidator;
use App\Brain\Journal\InvalidEntry;
use App\Brain\Journal\JournalEntry;
use App\Brain\Journal\Kind;
use App\Brain\Journal\Ref;
use App\Brain\Journal\ReviewPolicy;
use App\Brain\Store\JournalStore;
use App\Platform\Access\LearnerScope;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Unprocessable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Appends entries to one learner's journal (ADR 0002 §3–6, §9).
 *
 * - Validates every entry against the ADR 0002 contracts.
 * - Assigns positions one append at a time per learner, under a lock on the
 *   learner row: strictly increasing and without gaps.
 * - The same ID (or capture key) with the same content is a duplicate; with
 *   different content, a conflict.
 * - References must resolve inside this learner's own journal. A reference
 *   to another learner's entry gets exactly the same answer as one that
 *   doesn't exist.
 * - Refuses reviews that ADR 0002 §6 forbids.
 * - `received_at` is always the server's time.
 *
 * Callers are trusted server code: adapters set the actor from the
 * Principal, never from request input.
 */
final class JournalWriter
{
    /** Entity reference types, as opposed to references to journal entries. */
    private const ENTITY_TYPES = ['topic', 'question', 'misconception', 'task', 'activity', 'source', 'course', 'module'];

    /** Record types that introduce an entity others can reference. */
    private const RECORD_ENTITIES = ['task', 'activity', 'source', 'course', 'module'];

    public function __construct(private JournalStore $store) {}

    public function append(LearnerScope $scope, array $spec): AppendResult
    {
        return $this->appendBatch($scope, [$spec])[0];
    }

    /**
     * All or nothing: if one entry is refused, none are stored.
     *
     * @param  list<array>  $specs
     * @return list<AppendResult>
     */
    public function appendBatch(LearnerScope $scope, array $specs): array
    {
        return DB::transaction(function () use ($scope, $specs) {
            $learner = $this->store->lockLearner($scope);
            $position = (int) $learner->journal_position;
            $receivedAt = new DateTimeImmutable('@'.now()->format('U.u'));

            /** @var array<string, JournalEntry> $batch entries appended in this call, by ID */
            $batch = [];
            /** @var array<string, string> $fingerprints their fingerprints, by ID */
            $fingerprints = [];
            /** @var array<string, true> $batchEntities entities introduced in this call */
            $batchEntities = [];
            $results = [];

            foreach (array_values($specs) as $i => $spec) {
                $spec = $this->normalise($spec, (string) $learner->timezone, $scope);
                $fingerprint = self::fingerprint($spec);

                $duplicate = $this->duplicateOf($scope, $spec, $fingerprint, $batch, $fingerprints);
                if ($duplicate !== null) {
                    $results[] = new AppendResult(AppendResult::DUPLICATE, $duplicate);

                    continue;
                }

                $refs = EntryValidator::references($spec);
                $this->assertReferencesExist($scope, $spec, $refs, $batch, $batchEntities);

                $entry = EntryFactory::make($spec, $scope->learnerId, ++$position, $receivedAt);
                $this->assertReviewAllowed($scope, $entry, $batch);

                [$indexRefs, $introduced] = self::indexRefs($spec, $refs);
                $this->store->insert($scope, $entry, $spec['content'] ?? [], $fingerprint, $indexRefs);

                $batch[$entry->id] = $entry;
                $fingerprints[$entry->id] = $fingerprint;
                foreach ($introduced as $key) {
                    $batchEntities[$key] = true;
                }
                $results[] = new AppendResult(AppendResult::RECORDED, $entry);
            }

            $this->store->setPosition($scope, $position);

            return $results;
        }, 3);
    }

    /** Validates, then fills in defaults and puts every time in UTC, so equal entries fingerprint equally. */
    private function normalise(array $spec, string $learnerTimezone, LearnerScope $scope): array
    {
        try {
            EntryValidator::validate($spec);
        } catch (InvalidEntry $e) {
            throw new Unprocessable('invalid_entry', 'The entry does not meet the journal contract: '.$e->getMessage(), array_filter(['field' => $e->field]));
        }

        if ($spec['actor']['type'] === 'learner' && $spec['actor']['id'] !== $scope->learnerId) {
            throw new Unprocessable('invalid_entry', 'A learner can only write to their own journal.', ['field' => 'actor.id']);
        }

        unset($spec['received_at']);
        $spec['type'] ??= 'core.'.$spec['kind'];
        $spec['type_version'] ??= 1;
        $spec['precision'] ??= 'exact';
        $spec['tz'] ??= $learnerTimezone;
        $spec['actor']['channel'] ??= 'web';
        foreach (['occurred_at', 'occurred_until', 'recorded_at'] as $key) {
            if (isset($spec[$key])) {
                $spec[$key] = (new DateTimeImmutable($spec[$key]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
            }
        }

        return $spec;
    }

    /** SHA-256 of the entry without its ID, with object keys sorted. */
    private static function fingerprint(array $spec): string
    {
        unset($spec['id']);

        return hash('sha256', json_encode(self::canonical($spec), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The already-stored entry this one duplicates, or null when it is new. Throws on a conflict.
     *
     * @param  array<string, JournalEntry>  $batch
     * @param  array<string, string>  $fingerprints
     */
    private function duplicateOf(LearnerScope $scope, array $spec, string $fingerprint, array $batch, array $fingerprints): ?JournalEntry
    {
        $id = $spec['id'];
        if (isset($batch[$id])) {
            return $fingerprints[$id] === $fingerprint ? $batch[$id] : throw self::idConflict();
        }

        $row = $this->store->row($scope, $id);
        if ($row !== null) {
            return $row->fingerprint === $fingerprint ? $this->store->entry($scope, $id) : throw self::idConflict();
        }

        if (isset($spec['capture_key'])) {
            foreach ($batch as $entry) {
                if ($entry->captureKey === $spec['capture_key']) {
                    return $fingerprints[$entry->id] === $fingerprint ? $entry : throw self::captureKeyConflict();
                }
            }
            $row = $this->store->rowByCaptureKey($scope, $spec['capture_key']);
            if ($row !== null) {
                return $row->fingerprint === $fingerprint ? $this->store->entry($scope, $row->id) : throw self::captureKeyConflict();
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $refs
     * @param  array<string, JournalEntry>  $batch
     */
    private function assertReferencesExist(LearnerScope $scope, array $spec, array $refs, array $batch, array $batchEntities): void
    {
        $defined = self::definedBy($spec);
        $entryRefs = [];
        $entityRefs = [];

        foreach ($refs as [$role, $ref]) {
            [$type, $id] = Ref::parse($ref);
            if ($type === 'mention') {
                $entryRefs[explode('/', $id)[0]][] = 'event';
            } elseif ($type === 'event' || $type === 'claim') {
                $entryRefs[$id][] = $type;
            } elseif (in_array($type, self::ENTITY_TYPES, true) && ! in_array("{$type}:{$id}", $defined, true)) {
                $entityRefs["{$type}:{$id}"] = [$type, $id];
            }
        }

        $stored = $this->store->kindsOf($scope, array_values(array_diff(array_keys($entryRefs), array_keys($batch))));
        foreach ($entryRefs as $id => $types) {
            $kind = isset($batch[$id]) ? $batch[$id]->kind->value : ($stored[$id] ?? null);
            if ($kind === null) {
                throw self::unknownReference();
            }
            foreach (array_unique($types) as $type) {
                if (($type === 'claim') !== ($kind === Kind::Claim->value)) {
                    throw new Unprocessable('invalid_entry', 'Use "claim:" for claims and "event:" for every other entry.');
                }
            }
        }

        $missing = array_diff_key($entityRefs, $batchEntities);
        $found = $this->store->existingEntities($scope, array_values($missing));
        if (array_diff_key($missing, $found) !== []) {
            throw self::unknownReference();
        }
    }

    /** @param array<string, JournalEntry> $batch */
    private function assertReviewAllowed(LearnerScope $scope, JournalEntry $entry, array $batch): void
    {
        if ($entry->claimType() !== 'reviews' || ($entry->body['review']['state'] ?? null) !== 'accepted') {
            return;
        }

        $load = fn (string $id) => $batch[$id] ?? $this->store->entry($scope, $id);
        $target = $load(Ref::id($entry->body['targets'][0]));
        $judged = null;
        if ($target instanceof JournalEntry && $target->claimType() === 'judges') {
            $judged = $load(Ref::id($target->body['targets'][0]));
        }

        $violation = ReviewPolicy::violation($entry, $target, $judged);
        if ($violation !== null) {
            throw new Unprocessable('review_not_allowed', $violation['message'], array_filter(['use' => $violation['use']]));
        }
    }

    /** @return list<string> "type:id" of entities this entry creates */
    private static function definedBy(array $spec): array
    {
        $body = $spec['body'] ?? [];
        if ($spec['kind'] === 'claim' && ($body['type'] ?? null) === 'defines') {
            return [$body['targets'][0]];
        }
        if ($spec['kind'] === 'record' && in_array($body['record_type'] ?? null, self::RECORD_ENTITIES, true)) {
            return [$body['record_type'].':'.$body['record_id']];
        }

        return [];
    }

    /**
     * The rows for the reference index, and the entities this entry introduces.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<string>}
     */
    private static function indexRefs(array $spec, array $refs): array
    {
        $introduced = self::definedBy($spec);
        $role = $spec['kind'] === 'record' ? 'record' : 'defines';
        foreach ($introduced as $ref) {
            $refs[] = [$role, $ref];
        }

        return [$refs, $introduced];
    }

    private static function unknownReference(): Unprocessable
    {
        return new Unprocessable('unknown_reference', "A reference does not exist in this learner's journal.");
    }

    private static function idConflict(): Conflict
    {
        return new Conflict('id_conflict', 'An entry with this ID already exists with different content.');
    }

    private static function captureKeyConflict(): Conflict
    {
        return new Conflict('capture_key_conflict', 'This capture key was already used for different content.');
    }
}
