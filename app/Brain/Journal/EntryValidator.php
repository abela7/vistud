<?php

namespace App\Brain\Journal;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Checks an entry specification (docs/architecture/contracts.md) against the
 * contracts in ADR 0002 §3–5. Pure: it looks only at the specification.
 * Whether references exist, and whether a review is allowed, need the rest
 * of the journal, so the writer checks those.
 */
final class EntryValidator
{
    /** ID tokens: the same rule as App\Platform\Ids::TOKEN_PATTERN. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const MAX_CONTENT_BYTES = 1048576;

    private const TOP_LEVEL = [
        'id', 'kind', 'type', 'type_version', 'actor', 'origin', 'occurred_at', 'occurred_until', 'precision', 'tz',
        'recorded_at', 'received_at', 'capture_key', 'session', 'activity', 'links', 'mentions', 'body', 'content',
    ];

    public static function validate(array $spec): void
    {
        foreach (array_keys($spec) as $key) {
            if (! in_array($key, self::TOP_LEVEL, true)) {
                throw new InvalidEntry('Unknown field.', (string) $key);
            }
        }
        foreach (['id', 'kind', 'actor', 'occurred_at'] as $key) {
            if (! isset($spec[$key])) {
                throw new InvalidEntry('Required.', $key);
            }
        }

        self::id($spec['id'], 'id');
        $kind = Kind::tryFrom((string) $spec['kind']) ?? throw new InvalidEntry('Unknown kind.', 'kind');
        if (isset($spec['type']) && preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', (string) $spec['type']) !== 1) {
            throw new InvalidEntry('Must look like "core.attempt".', 'type');
        }
        if (isset($spec['type_version']) && (! is_int($spec['type_version']) || $spec['type_version'] < 1)) {
            throw new InvalidEntry('Must be a positive integer.', 'type_version');
        }

        self::actor($spec['actor'] ?? null);
        self::time($spec['occurred_at'], 'occurred_at');
        foreach (['occurred_until', 'recorded_at', 'received_at'] as $key) {
            if (isset($spec[$key])) {
                self::time($spec[$key], $key);
            }
        }
        self::in($spec['precision'] ?? 'exact', array_column(Precision::cases(), 'value'), 'precision');
        if (isset($spec['tz'])) {
            self::timezone($spec['tz']);
        }
        if (isset($spec['capture_key']) && (! is_string($spec['capture_key']) || $spec['capture_key'] === '' || strlen($spec['capture_key']) > 128)) {
            throw new InvalidEntry('Must be a string of 1 to 128 characters.', 'capture_key');
        }
        foreach (['session', 'activity'] as $key) {
            if (isset($spec[$key])) {
                self::id($spec[$key], $key);
            }
        }

        if ($kind->isObservation()) {
            self::in($spec['origin'] ?? null, Vocabulary::ORIGINS, 'origin');
        } elseif (isset($spec['origin'])) {
            throw new InvalidEntry('Only observations have an origin.', 'origin');
        }

        self::links($spec['links'] ?? []);
        $content = self::content($spec['content'] ?? []);
        self::mentions($spec['mentions'] ?? [], $content);

        $body = $spec['body'] ?? [];
        if (! is_array($body)) {
            throw new InvalidEntry('Must be an object.', 'body');
        }
        match ($kind) {
            Kind::Exposure => self::exposure($body, $spec),
            Kind::Attempt => self::attempt($body),
            Kind::Question => null,
            Kind::SelfReport => self::selfReport($body, $spec),
            Kind::Claim => self::claim($body),
            Kind::Record => self::record($body),
            Kind::Amendment => self::amendment($body),
        };
    }

    /** @return list<array{0: string, 1: string}> every reference in the specification, as [role, ref] */
    public static function references(array $spec): array
    {
        $refs = [];
        foreach ($spec['links'] ?? [] as $link) {
            $refs[] = ['link:'.$link['rel'], $link['target']];
        }
        $body = $spec['body'] ?? [];
        foreach (['targets' => 'target', 'derived_from' => 'derived_from', 'supersedes' => 'supersedes'] as $key => $role) {
            foreach ($body[$key] ?? [] as $ref) {
                $refs[] = [$role, $ref];
            }
        }
        $value = $body['value'] ?? [];
        foreach (['entity', 'survivor'] as $key) {
            if (isset($value[$key])) {
                $refs[] = ['value', $value[$key]];
            }
        }
        foreach ($value['into'] ?? [] as $ref) {
            $refs[] = ['value', $ref];
        }
        foreach ($value['assignments'] ?? [] as $assignment) {
            $refs[] = ['value', $assignment['ref']];
            $refs[] = ['value', $assignment['to']];
        }
        foreach ($value['topics'] ?? [] as $topic) {
            $refs[] = ['value', is_array($topic) ? $topic['topic'] : $topic];
        }
        foreach ($value['misconceptions'] ?? [] as $misconception) {
            $refs[] = ['value', $misconception['misconception']];
        }
        foreach ($value['demonstrates'] ?? [] as $question) {
            $refs[] = ['value', $question];
        }
        foreach ($value['answer'] ?? [] as $answer) {
            $refs[] = ['value', $answer['question']];
        }
        foreach ($value['effect'] ?? [] as $effect) {
            $refs[] = ['value', $effect['target']];
        }
        if (($spec['kind'] ?? null) === 'attempt' && isset($body['task'])) {
            $refs[] = ['task', 'task:'.$body['task']];
        }

        return $refs;
    }

    private static function actor(mixed $actor): void
    {
        if (! is_array($actor)) {
            throw new InvalidEntry('Must be an object.', 'actor');
        }
        self::in($actor['type'] ?? null, Vocabulary::ACTOR_TYPES, 'actor.type');
        self::in($actor['channel'] ?? 'web', Vocabulary::CHANNELS, 'actor.channel');
        self::id($actor['id'] ?? null, 'actor.id');
    }

    private static function links(mixed $links): void
    {
        if (! is_array($links) || ! array_is_list($links)) {
            throw new InvalidEntry('Must be a list.', 'links');
        }
        foreach ($links as $i => $link) {
            self::in($link['rel'] ?? null, Vocabulary::LINK_RELS, "links.{$i}.rel");
            self::ref($link['target'] ?? null, "links.{$i}.target");
            if (isset($link['role'])) {
                self::in($link['role'], Vocabulary::LINK_ROLES, "links.{$i}.role");
            }
        }
    }

    /** @return array<string, string> */
    private static function content(mixed $content): array
    {
        if (! is_array($content) || ($content !== [] && array_is_list($content))) {
            throw new InvalidEntry('Must be an object of named text fields.', 'content');
        }
        foreach ($content as $field => $text) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $field) !== 1) {
                throw new InvalidEntry('Content field names are lower-case words of up to 32 characters.', 'content');
            }
            if (! is_string($text) || strlen($text) > self::MAX_CONTENT_BYTES) {
                throw new InvalidEntry('Must be text of at most 1 MiB.', "content.{$field}");
            }
        }

        return $content;
    }

    private static function mentions(mixed $mentions, array $content): void
    {
        if (! is_array($mentions) || ! array_is_list($mentions)) {
            throw new InvalidEntry('Must be a list.', 'mentions');
        }
        $seen = [];
        foreach ($mentions as $i => $mention) {
            $n = $mention['n'] ?? null;
            $field = $mention['field'] ?? null;
            $start = $mention['start'] ?? null;
            $end = $mention['end'] ?? null;
            if (! is_int($n) || $n < 1 || isset($seen[$n])) {
                throw new InvalidEntry('Each mention needs a unique positive n.', "mentions.{$i}.n");
            }
            $seen[$n] = true;
            if (! is_string($field) || ! isset($content[$field])) {
                throw new InvalidEntry('Must name a content field of this entry.', "mentions.{$i}.field");
            }
            if (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start || $end > mb_strlen($content[$field])) {
                throw new InvalidEntry('Must be a character range inside the field.', "mentions.{$i}");
            }
        }
    }

    private static function exposure(array $body, array $spec): void
    {
        self::in($body['format'] ?? null, Vocabulary::FORMATS, 'body.format');
        foreach ($body['approach'] ?? [] as $approach) {
            self::in($approach, Vocabulary::APPROACHES, 'body.approach');
        }
        if (($body['by']['type'] ?? null) === 'chat_ai' && ! isset($spec['content']['summary'])) {
            throw new InvalidEntry('An explanation from a chat LLM needs a summary.', 'content.summary');
        }
    }

    private static function attempt(array $body): void
    {
        self::id($body['task'] ?? null, 'body.task');
        if (isset($body['task_revision']) && (! is_int($body['task_revision']) || $body['task_revision'] < 1)) {
            throw new InvalidEntry('Must be a positive integer.', 'body.task_revision');
        }
        self::in($body['form'] ?? null, Vocabulary::FORMS, 'body.form');
        self::in($body['support'] ?? null, Vocabulary::SUPPORT, 'body.support');
        self::in($body['setting'] ?? null, Vocabulary::SETTINGS, 'body.setting');
        self::in($body['outcome'] ?? null, Vocabulary::OUTCOMES, 'body.outcome');
        self::in($body['judged_by'] ?? null, Vocabulary::JUDGED_BY, 'body.judged_by');

        if (isset($body['checker'])) {
            $checker = $body['checker'];
            self::id($checker['id'] ?? null, 'body.checker.id');
            if (! isset($checker['version']) || (! is_int($checker['version']) && ! is_string($checker['version']))) {
                throw new InvalidEntry('Required.', 'body.checker.version');
            }
            self::in($checker['key_source'] ?? null, Vocabulary::KEY_SOURCES, 'body.checker.key_source');
        }
        // ADR 0002 §4 and ADR 0003 §9.4: `auto` needs a trusted checker. A key
        // the learner wrote is never trusted; that match is `self`.
        if ($body['judged_by'] === 'auto') {
            if (! isset($body['checker'])) {
                throw new InvalidEntry('judged_by auto needs a checker.', 'body.checker');
            }
            if (! in_array($body['checker']['key_source'], Vocabulary::TRUSTED_KEY_SOURCES, true)) {
                throw new InvalidEntry('A key the learner wrote is not trusted. Record the attempt as judged_by self.', 'body.checker.key_source');
            }
        }
        if (isset($body['score']) && (! is_numeric($body['score']['raw'] ?? null) || ! is_numeric($body['score']['max'] ?? null))) {
            throw new InvalidEntry('Needs numeric raw and max.', 'body.score');
        }
    }

    private static function selfReport(array $body, array $spec): void
    {
        self::in($body['stance'] ?? null, Vocabulary::STANCES, 'body.stance');
        $about = array_filter($spec['links'] ?? [], fn ($l) => ($l['rel'] ?? null) === 'about');
        if ($about === []) {
            throw new InvalidEntry('A self-report must be about at least one topic or question.', 'links');
        }
    }

    private static function claim(array $body): void
    {
        self::in($body['type'] ?? null, Vocabulary::CLAIM_TYPES, 'body.type');
        self::in($body['method']['kind'] ?? null, Vocabulary::METHOD_KINDS, 'body.method.kind');
        self::id($body['method']['id'] ?? null, 'body.method.id');
        self::in($body['review']['state'] ?? null, ['accepted', 'pending'], 'body.review.state');
        $confidence = $body['confidence'] ?? null;
        if ($confidence !== null && (! is_numeric($confidence) || $confidence < 0 || $confidence > 1)) {
            throw new InvalidEntry('Must be between 0 and 1, or null.', 'body.confidence');
        }
        if (! is_array($body['targets'] ?? null) || $body['targets'] === []) {
            throw new InvalidEntry('A claim needs at least one target.', 'body.targets');
        }
        foreach (['targets', 'derived_from', 'supersedes'] as $key) {
            foreach ($body[$key] ?? [] as $i => $ref) {
                self::ref($ref, "body.{$key}.{$i}");
            }
        }

        $value = $body['value'] ?? [];
        match ($body['type']) {
            'defines' => self::defines($body, $value),
            'refers_to' => self::ref($value['entity'] ?? null, 'body.value.entity'),
            'same_as' => count($body['targets']) === 2 ? null : throw new InvalidEntry('same_as needs two targets.', 'body.targets'),
            'splits_into' => is_array($value['into'] ?? null) ? null : throw new InvalidEntry('splits_into needs value.into.', 'body.value.into'),
            'relates' => self::relates($body, $value),
            'judges' => self::judges($value),
            'reviews' => self::reviews($body, $value),
        };
    }

    private static function defines(array $body, array $value): void
    {
        self::in($value['entity_type'] ?? null, Vocabulary::ENTITY_TYPES, 'body.value.entity_type');
        if (Ref::type($body['targets'][0]) !== $value['entity_type']) {
            throw new InvalidEntry('The target must be the entity being defined.', 'body.targets');
        }
    }

    private static function relates(array $body, array $value): void
    {
        self::in($value['relation'] ?? null, Vocabulary::RELATIONS, 'body.value.relation');
        if (count($body['targets']) !== 2) {
            throw new InvalidEntry('relates needs a from and a to target.', 'body.targets');
        }
        if ($value['relation'] === 'emphasizes') {
            $cites = array_filter($body['derived_from'] ?? [], fn ($r) => Ref::type($r) === 'source' && Ref::locator($r) !== null);
            if ($cites === []) {
                throw new InvalidEntry('emphasizes must cite a location in a source.', 'body.derived_from');
            }
        }
    }

    private static function judges(array $value): void
    {
        $allowed = [...Vocabulary::ATTEMPT_FACETS, ...Vocabulary::EXPOSURE_FACETS];
        if ($value === [] || array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidEntry('judges needs at least one known facet.', 'body.value');
        }
        if (isset($value['overall'])) {
            self::in($value['overall'], ['correct', 'partial', 'incorrect'], 'body.value.overall');
        }
        if (isset($value['cause'])) {
            self::in($value['cause'], Vocabulary::CAUSES, 'body.value.cause');
        }
    }

    private static function reviews(array $body, array $value): void
    {
        self::in($value['decision'] ?? null, Vocabulary::DECISIONS, 'body.value.decision');
        if (count($body['targets']) !== 1) {
            throw new InvalidEntry('A review has exactly one target.', 'body.targets');
        }
    }

    private static function record(array $body): void
    {
        self::in($body['record_type'] ?? null, Vocabulary::RECORD_TYPES, 'body.record_type');
        self::id($body['record_id'] ?? null, 'body.record_id');

        match ($body['record_type']) {
            'task' => self::recordTask($body),
            'activity' => self::in($body['kind'] ?? null, Vocabulary::ACTIVITY_KINDS, 'body.kind'),
            'course' => is_string($body['title'] ?? null) ? null : throw new InvalidEntry('Required.', 'body.title'),
            'module' => self::recordModule($body),
            default => null,
        };
    }

    private static function recordTask(array $body): void
    {
        if (! is_string($body['key'] ?? null) || $body['key'] === '' || strlen($body['key']) > 191) {
            throw new InvalidEntry('A task needs a key.', 'body.key');
        }
        if (isset($body['revision']) && (! is_int($body['revision']) || $body['revision'] < 1)) {
            throw new InvalidEntry('Must be a positive integer.', 'body.revision');
        }
        if (isset($body['status'])) {
            self::in($body['status'], Vocabulary::TASK_STATUSES, 'body.status');
        }
    }

    private static function recordModule(array $body): void
    {
        self::id($body['course'] ?? null, 'body.course');
        if (! is_string($body['title'] ?? null)) {
            throw new InvalidEntry('Required.', 'body.title');
        }
    }

    private static function amendment(array $body): void
    {
        self::in($body['action'] ?? null, Vocabulary::AMENDMENT_ACTIONS, 'body.action');
        self::in($body['reason'] ?? null, Vocabulary::AMENDMENT_REASONS, 'body.reason');
        if (! is_array($body['targets'] ?? null) || $body['targets'] === []) {
            throw new InvalidEntry('An amendment needs at least one target.', 'body.targets');
        }
        foreach ($body['targets'] as $i => $ref) {
            self::ref($ref, "body.targets.{$i}");
        }
    }

    private static function ref(mixed $ref, string $field): void
    {
        if (! is_string($ref)) {
            throw new InvalidEntry('Must be a reference such as "topic:<id>".', $field);
        }
        try {
            [$type, $id] = Ref::parse($ref);
        } catch (InvalidEntry) {
            throw new InvalidEntry('Must be a reference such as "topic:<id>".', $field);
        }
        self::in($type, Vocabulary::REF_TYPES, $field);
        if ($type === 'mention') {
            if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,63}/[1-9][0-9]*$#', $id) !== 1) {
                throw new InvalidEntry('A mention reference is "mention:<event>/<n>".', $field);
            }

            return;
        }
        self::id($id, $field);
    }

    private static function id(mixed $id, string $field): void
    {
        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidEntry('Must be an ID: letters, digits, ".", "_" or "-", up to 64 characters.', $field);
        }
    }

    private static function time(mixed $value, string $field): void
    {
        if (! is_string($value) || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            throw new InvalidEntry('Must be an ISO 8601 time with an offset.', $field);
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new InvalidEntry('Must be an ISO 8601 time with an offset.', $field);
        }
    }

    private static function timezone(mixed $tz): void
    {
        try {
            new DateTimeZone((string) $tz);
        } catch (Throwable) {
            throw new InvalidEntry('Must be an IANA time zone.', 'tz');
        }
    }

    private static function in(mixed $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidEntry('Not an allowed value.', $field);
        }
    }
}
