<?php

namespace App\Brain\Journal;

/**
 * Checks an entry specification against the contracts in ADR 0002 §4–5.
 * Review permissions are checked separately, because they need the target.
 */
final class EntryValidator
{
    public static function validate(array $spec): void
    {
        foreach (['id', 'kind', 'actor', 'occurred_at'] as $key) {
            if (! isset($spec[$key])) {
                throw new InvalidEntry("Missing '{$key}'.");
            }
        }

        $kind = Kind::tryFrom($spec['kind']) ?? throw new InvalidEntry("Unknown kind '{$spec['kind']}'.");
        self::in($spec['actor']['type'] ?? null, Vocabulary::ACTOR_TYPES, 'actor.type');
        self::in($spec['actor']['channel'] ?? 'web', Vocabulary::CHANNELS, 'actor.channel');
        self::in($spec['precision'] ?? 'exact', array_column(Precision::cases(), 'value'), 'precision');
        if (($spec['actor']['id'] ?? '') === '') {
            throw new InvalidEntry('Missing actor.id.');
        }

        if ($kind->isObservation()) {
            self::in($spec['origin'] ?? null, Vocabulary::ORIGINS, 'origin');
        }

        foreach ($spec['links'] ?? [] as $link) {
            self::in($link['rel'] ?? null, ['about', 'cites', 'responds_to', 'triggered_by'], 'links.rel');
            Ref::parse($link['target'] ?? '');
        }

        $body = $spec['body'] ?? [];
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

    private static function exposure(array $body, array $spec): void
    {
        self::in($body['format'] ?? null, Vocabulary::FORMATS, 'body.format');
        foreach ($body['approach'] ?? [] as $approach) {
            self::in($approach, Vocabulary::APPROACHES, 'body.approach');
        }
        if (($body['by']['type'] ?? null) === 'chat_ai' && ! isset($spec['content']['summary'])) {
            throw new InvalidEntry('An explanation from a chat LLM needs a summary.');
        }
    }

    private static function attempt(array $body): void
    {
        if (! is_string($body['task'] ?? null) || $body['task'] === '') {
            throw new InvalidEntry('An attempt needs a task.');
        }
        self::in($body['form'] ?? null, Vocabulary::FORMS, 'body.form');
        self::in($body['support'] ?? null, Vocabulary::SUPPORT, 'body.support');
        self::in($body['setting'] ?? null, Vocabulary::SETTINGS, 'body.setting');
        self::in($body['outcome'] ?? null, Vocabulary::OUTCOMES, 'body.outcome');
        self::in($body['judged_by'] ?? null, Vocabulary::JUDGED_BY, 'body.judged_by');
    }

    private static function selfReport(array $body, array $spec): void
    {
        self::in($body['stance'] ?? null, Vocabulary::STANCES, 'body.stance');
        $about = array_filter($spec['links'] ?? [], fn ($l) => $l['rel'] === 'about');
        if ($about === []) {
            throw new InvalidEntry('A self-report must be about at least one topic or question.');
        }
    }

    private static function claim(array $body): void
    {
        self::in($body['type'] ?? null, Vocabulary::CLAIM_TYPES, 'body.type');
        self::in($body['method']['kind'] ?? null, Vocabulary::METHOD_KINDS, 'body.method.kind');
        self::in($body['review']['state'] ?? null, ['accepted', 'pending'], 'body.review.state');
        if (($body['targets'] ?? []) === []) {
            throw new InvalidEntry('A claim needs at least one target.');
        }
        foreach ([...$body['targets'], ...($body['derived_from'] ?? []), ...($body['supersedes'] ?? [])] as $ref) {
            Ref::parse($ref);
        }

        $value = $body['value'] ?? [];
        match ($body['type']) {
            'defines' => self::in($value['entity_type'] ?? null, Vocabulary::ENTITY_TYPES, 'value.entity_type'),
            'refers_to' => Ref::parse($value['entity'] ?? ''),
            'same_as' => count($body['targets']) === 2 ? null : throw new InvalidEntry('same_as needs two targets.'),
            'splits_into' => is_array($value['into'] ?? null) ? null : throw new InvalidEntry('splits_into needs value.into.'),
            'relates' => self::relates($body, $value),
            'judges' => self::judges($value),
            'reviews' => self::in($value['decision'] ?? null, Vocabulary::DECISIONS, 'value.decision'),
        };
    }

    private static function relates(array $body, array $value): void
    {
        self::in($value['relation'] ?? null, Vocabulary::RELATIONS, 'value.relation');
        if (count($body['targets']) !== 2) {
            throw new InvalidEntry('relates needs a from and a to target.');
        }
        if ($value['relation'] === 'emphasizes') {
            $cites = array_filter($body['derived_from'] ?? [], fn ($r) => Ref::type($r) === 'source');
            if ($cites === []) {
                throw new InvalidEntry('emphasizes must cite a source location.');
            }
        }
    }

    private static function judges(array $value): void
    {
        $allowed = [...Vocabulary::ATTEMPT_FACETS, ...Vocabulary::EXPOSURE_FACETS];
        if ($value === [] || array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidEntry('judges needs at least one known facet.');
        }
        if (isset($value['cause'])) {
            self::in($value['cause'], Vocabulary::CAUSES, 'value.cause');
        }
    }

    private static function record(array $body): void
    {
        self::in($body['record_type'] ?? null, Vocabulary::RECORD_TYPES, 'body.record_type');
        if (($body['record_id'] ?? '') === '') {
            throw new InvalidEntry('A record needs a record_id.');
        }
    }

    private static function amendment(array $body): void
    {
        self::in($body['action'] ?? null, Vocabulary::AMENDMENT_ACTIONS, 'body.action');
        self::in($body['reason'] ?? null, Vocabulary::AMENDMENT_REASONS, 'body.reason');
        if (($body['targets'] ?? []) === []) {
            throw new InvalidEntry('An amendment needs at least one target.');
        }
    }

    private static function in(mixed $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            $shown = is_scalar($value) ? (string) $value : gettype($value);
            throw new InvalidEntry("Invalid {$field}: '{$shown}'.");
        }
    }
}
