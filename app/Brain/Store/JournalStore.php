<?php

namespace App\Brain\Store;

use App\Brain\Journal\Actor;
use App\Brain\Journal\Interval;
use App\Brain\Journal\JournalEntry;
use App\Brain\Journal\Kind;
use App\Brain\Journal\Precision;
use App\Brain\Journal\Ref;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for the journal (docs/architecture/schema.md). Every read and
 * write is scoped to one learner through LearnerTables. Internal to the
 * Brain: other modules use JournalReader and JournalWriter.
 */
final class JournalStore
{
    private const DB_TIME = 'Y-m-d H:i:s.u';

    /**
     * Locks the learner's row until the transaction ends, so positions are
     * assigned one append at a time per learner.
     *
     * @return object{journal_position: int, timezone: string}
     */
    public function lockLearner(LearnerScope $scope): object
    {
        $row = DB::table('learners')->where('id', $scope->learnerId)->lockForUpdate()->first(['journal_position', 'timezone']);

        return $row ?? throw new NotFound;
    }

    public function setPosition(LearnerScope $scope, int $position): void
    {
        DB::table('learners')->where('id', $scope->learnerId)->update(['journal_position' => $position, 'updated_at' => now()]);
    }

    public function row(LearnerScope $scope, string $id): ?object
    {
        return LearnerTables::query($scope, 'journal_entries')->where('id', $id)->first();
    }

    public function rowByCaptureKey(LearnerScope $scope, string $captureKey): ?object
    {
        return LearnerTables::query($scope, 'journal_entries')->where('capture_key', $captureKey)->first();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => kind, for the IDs that exist in this learner's journal
     */
    public function kindsOf(LearnerScope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LearnerTables::query($scope, 'journal_entries')->whereIn('id', $ids)->pluck('kind', 'id')->all();
    }

    /**
     * Entities introduced into this learner's journal: by a `defines` claim,
     * or as a record (task, activity, source, course, module).
     *
     * @param  list<array{0: string, 1: string}>  $entities  [type, id] pairs
     * @return array<string, true> "type:id" => true for those that exist
     */
    public function existingEntities(LearnerScope $scope, array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $query = LearnerTables::query($scope, 'journal_refs')->whereIn('role', ['defines', 'record']);
        $query->where(function ($q) use ($entities) {
            foreach ($entities as [$type, $id]) {
                $q->orWhere(fn ($pair) => $pair->where('ref_type', $type)->where('ref_id', $id));
            }
        });

        $found = [];
        foreach ($query->get(['ref_type', 'ref_id']) as $row) {
            $found[$row->ref_type.':'.$row->ref_id] = true;
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $content
     * @param  list<array{0: string, 1: string}>  $refs  [role, ref] pairs for the reference index
     */
    public function insert(LearnerScope $scope, JournalEntry $entry, array $content, string $fingerprint, array $refs): void
    {
        LearnerTables::insert($scope, 'journal_entries', [
            'id' => $entry->id,
            'position' => $entry->position,
            'kind' => $entry->kind->value,
            'type' => $entry->type,
            'type_version' => $entry->typeVersion,
            'actor_type' => $entry->actor->type,
            'actor_id' => $entry->actor->id,
            'actor_channel' => $entry->actor->channel,
            'origin' => $entry->origin,
            'occurred_at' => self::dbTime($entry->occurredAt),
            'occurred_until' => $entry->occurredUntil === null ? null : self::dbTime($entry->occurredUntil),
            'precision' => $entry->precision->value,
            'tz' => $entry->tz,
            'interval_lo' => self::dbTime(Interval::toDateTime($entry->interval->lo)),
            'interval_hi' => self::dbTime(Interval::toDateTime($entry->interval->hi)),
            'recorded_at' => self::dbTime($entry->recordedAt),
            'received_at' => self::dbTime($entry->receivedAt),
            'capture_key' => $entry->captureKey,
            'session_id' => $entry->sessionId,
            'activity_id' => $entry->activityId,
            'links' => json_encode($entry->links, JSON_THROW_ON_ERROR),
            'body' => json_encode((object) $entry->body, JSON_THROW_ON_ERROR),
            'content_fields' => json_encode($entry->contentFields, JSON_THROW_ON_ERROR),
            'fingerprint' => $fingerprint,
        ]);

        LearnerTables::insert($scope, 'journal_content', array_map(
            fn (string $field, string $text) => ['entry_id' => $entry->id, 'field' => $field, 'text' => $text],
            array_keys($content),
            array_values($content),
        ));

        LearnerTables::insert($scope, 'journal_mentions', array_map(
            fn (array $m) => ['entry_id' => $entry->id, 'n' => $m['n'], 'field' => $m['field'], 'start' => $m['start'], 'end' => $m['end']],
            $entry->mentions,
        ));

        LearnerTables::insert($scope, 'journal_refs', array_map(function (array $pair) use ($entry) {
            [$role, $ref] = $pair;
            [$type, $id] = Ref::parse($ref);

            return ['entry_id' => $entry->id, 'role' => $role, 'ref_type' => $type, 'ref_id' => $id, 'locator' => Ref::locator($ref)];
        }, $refs));
    }

    /** @return list<JournalEntry> in position order */
    public function entries(LearnerScope $scope, ?int $upToPosition = null): array
    {
        $query = LearnerTables::query($scope, 'journal_entries')->orderBy('position');
        if ($upToPosition !== null) {
            $query->where('position', '<=', $upToPosition);
        }

        $mentions = [];
        foreach (LearnerTables::query($scope, 'journal_mentions')->orderBy('n')->get() as $m) {
            $mentions[$m->entry_id][] = ['n' => (int) $m->n, 'field' => $m->field, 'start' => (int) $m->start, 'end' => (int) $m->end];
        }

        return $query->get()->map(fn (object $row) => $this->toEntry($row, $mentions[$row->id] ?? []))->all();
    }

    public function entry(LearnerScope $scope, string $id): ?JournalEntry
    {
        $row = $this->row($scope, $id);

        return $row === null ? null : $this->toEntry($row, $this->mentions($scope, $id));
    }

    /** @return array<string, string> field => text; empty when blocked by a redaction */
    public function content(LearnerScope $scope, string $entryId): array
    {
        if ($this->isBlocked($scope, 'event', $entryId)) {
            return [];
        }

        return LearnerTables::query($scope, 'journal_content')->where('entry_id', $entryId)->orderBy('field')->pluck('text', 'field')->all();
    }

    /** @return list<array{n: int, field: string, start: int, end: int}> */
    public function mentions(LearnerScope $scope, string $entryId): array
    {
        return LearnerTables::query($scope, 'journal_mentions')->where('entry_id', $entryId)->orderBy('n')->get()
            ->map(fn ($m) => ['n' => (int) $m->n, 'field' => $m->field, 'start' => (int) $m->start, 'end' => (int) $m->end])
            ->all();
    }

    public function isBlocked(LearnerScope $scope, string $entityType, string $entityId): bool
    {
        return LearnerTables::query($scope, 'journal_blocks')->where('entity_type', $entityType)->where('entity_id', $entityId)->exists();
    }

    public function toEntry(object $row, array $mentions = []): JournalEntry
    {
        $time = fn (?string $value) => $value === null ? null : DateTimeImmutable::createFromFormat(self::DB_TIME, $value, new DateTimeZone('UTC'));

        return new JournalEntry(
            id: $row->id,
            learnerId: $row->learner_id,
            position: (int) $row->position,
            kind: Kind::from($row->kind),
            type: $row->type,
            typeVersion: (int) $row->type_version,
            actor: new Actor($row->actor_type, $row->actor_id, $row->actor_channel),
            origin: $row->origin,
            occurredAt: $time($row->occurred_at),
            occurredUntil: $time($row->occurred_until),
            precision: Precision::from($row->precision),
            tz: $row->tz,
            interval: new Interval(Interval::epoch($time($row->interval_lo)), Interval::epoch($time($row->interval_hi))),
            recordedAt: $time($row->recorded_at),
            receivedAt: $time($row->received_at),
            sessionId: $row->session_id,
            activityId: $row->activity_id,
            links: json_decode($row->links, true, flags: JSON_THROW_ON_ERROR),
            mentions: $mentions,
            body: json_decode($row->body, true, flags: JSON_THROW_ON_ERROR),
            contentFields: json_decode($row->content_fields, true, flags: JSON_THROW_ON_ERROR),
            captureKey: $row->capture_key,
        );
    }

    private static function dbTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::DB_TIME);
    }
}
