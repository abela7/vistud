<?php

namespace App\View;

use App\Brain\Journal\JournalEntry;
use App\Brain\Journal\Kind;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Plain words for a journal entry on the student's own pages (the list and
 * the entry page). Presentation only: M2's learning history replaces it.
 */
final class JournalEntryText
{
    public static function title(JournalEntry $entry): string
    {
        return match ($entry->kind) {
            Kind::Exposure => 'Studied',
            Kind::Attempt => 'Attempt',
            Kind::Question => 'Question',
            Kind::SelfReport => 'How you felt',
            Kind::Claim => 'ViStud noted',
            Kind::Record => Str::ucfirst(self::words((string) ($entry->body['record_type'] ?? 'record'))),
            Kind::Amendment => 'Correction',
        };
    }

    /** One short line under the title. */
    public static function summary(JournalEntry $entry): string
    {
        return match ($entry->kind) {
            Kind::Attempt => 'Task '.($entry->body['task'] ?? '?'),
            Kind::Claim => Str::ucfirst(self::words((string) ($entry->body['type'] ?? ''))).' '.implode(', ', $entry->body['targets'] ?? []),
            Kind::Record => (string) ($entry->body['title'] ?? $entry->body['key'] ?? $entry->body['record_id'] ?? ''),
            default => '',
        };
    }

    /** The attempt's outcome, if it has one: correct, partial, incorrect or unjudged. */
    public static function outcome(JournalEntry $entry): ?string
    {
        return $entry->kind === Kind::Attempt ? ($entry->body['outcome'] ?? null) : null;
    }

    public static function by(JournalEntry $entry): string
    {
        return match ($entry->actor->type) {
            'learner' => 'You',
            'chat_client' => 'Your chat assistant',
            'person' => 'Someone else',
            default => 'ViStud',
        };
    }

    public static function when(JournalEntry $entry): string
    {
        return $entry->occurredAt->setTimezone(new DateTimeZone($entry->tz))->format('j M Y, H:i');
    }

    /**
     * The entry's recorded facts, as label => value.
     *
     * @return array<string, string>
     */
    public static function details(JournalEntry $entry): array
    {
        $details = [];
        foreach ($entry->body as $key => $value) {
            $details[self::field((string) $key)] = self::value($value);
        }

        return $details;
    }

    /** A value in words: codes lose their underscores, and nested values read "key: value, …". */
    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_string($value) && preg_match('/^[a-z_]+$/', $value) === 1 => self::words($value),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(', ', array_map(self::value(...), $value)) ?: '—',
            is_array($value) => implode(', ', array_map(fn ($k, $v) => self::words((string) $k).': '.self::value($v), array_keys($value), $value)),
            default => '—',
        };
    }

    public static function field(string $name): string
    {
        return Str::ucfirst(self::words($name));
    }

    private static function words(string $code): string
    {
        return str_replace('_', ' ', $code);
    }
}
