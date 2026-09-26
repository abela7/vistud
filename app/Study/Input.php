<?php

namespace App\Study;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use DateTimeImmutable;

/** Checks the Study services share: cleaned text, optional dates, field refusals, and the owner's workspace. Internal to Study. */
final class Input
{
    /** Trimmed text with runs of spaces collapsed, or null when empty or not text. */
    public static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim((string) preg_replace('/\s+/u', ' ', $value)) : null;
    }

    /**
     * Optional `starts_on` and `ends_on` dates (Y-m-d), and the refusals for them.
     *
     * @return array{0: ?string, 1: ?string, 2: array<string, string>}
     */
    public static function dates(array $input): array
    {
        $date = function (string $key) use ($input): string|false|null {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : false;
        };
        $startsOn = $date('starts_on');
        $endsOn = $date('ends_on');

        $errors = array_filter([
            'starts_on' => $startsOn === false ? 'Enter a date.' : null,
            'ends_on' => match (true) {
                $endsOn === false => 'Enter a date.',
                is_string($startsOn) && is_string($endsOn) && $endsOn < $startsOn => 'The end date is before the start date.',
                default => null,
            },
        ]);

        return [$startsOn ?: null, $endsOn ?: null, $errors];
    }

    /** @param array<string, string> $errors field => message; nothing happens when empty */
    public static function refuse(array $errors): void
    {
        if ($errors !== []) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => array_map(fn ($message) => [$message], $errors)]);
        }
    }

    /** The owner's workspace row, or 404 exactly like a missing one. */
    public static function workspace(LearnerScope $scope, string $workspaceId, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'workspaces')->where('id', $workspaceId);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }
}
