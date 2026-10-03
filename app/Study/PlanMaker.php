<?php

namespace App\Study;

use App\Platform\Access\Principal;
use Carbon\CarbonImmutable;

/**
 * Making an assignment's plan with any AI (the owner's review, 2026-10-02): the student copies a prompt
 * (resources/prompts/assignment-plan.md, with the assignment and the brief they paste), pastes the AI's reply
 * back, looks at the parts, steps and criteria it read, and keeps them. Like App\Study\CardMaker, but for a plan.
 */
final class PlanMaker
{
    public const PROMPT = 'resources/prompts/assignment-plan.md';

    /** The most of a brief the prompt carries, in characters. */
    public const BRIEF_LIMIT = 12_000;

    public function __construct(private Workspaces $workspaces, private Activities $activities) {}

    /** The prompt to copy: what to make, how, and the assignment and its brief. */
    public function prompt(Principal $by, string $activityId, string $brief): string
    {
        $assignment = $this->activities->find($by, $activityId);
        $workspace = $this->workspaces->find($by, $assignment->workspaceId);
        $deadline = $assignment->dueOn === null
            ? 'none given'
            : ($assignment->dueAt()->format('l j F Y').($assignment->dueTime === null ? '' : ' at '.$assignment->dueTime));

        $now = CarbonImmutable::now($assignment->zone);
        $days = $assignment->dueOn === null ? null : (int) $now->startOfDay()->diffInDays(CarbonImmutable::parse($assignment->dueOn, $assignment->zone), false);
        $timeLeft = match (true) {
            $days === null => 'no deadline given',
            $days < 0 => 'the deadline has passed',
            $days === 0 => 'due today',
            $days < 14 => $days === 1 ? '1 day' : "{$days} days",
            default => "{$days} days (about ".round($days / 7).' weeks)',
        };

        $template = (string) file_get_contents(base_path(self::PROMPT));
        $template = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $template);
        $prompt = trim(strtr($template, [
            '{{course}}' => $workspace->name,
            '{{assignment}}' => $assignment->title,
            '{{kind}}' => strtolower($assignment->kindLabel()),
            '{{deadline}}' => $deadline,
            '{{today}}' => $now->format('l j F Y'),
            '{{time_left}}' => $timeLeft,
        ]));

        $brief = trim($brief);
        if (mb_strlen($brief) > self::BRIEF_LIMIT) {
            $brief = rtrim(mb_substr($brief, 0, self::BRIEF_LIMIT))."\n\n(The rest of the brief is left out.)";
        }

        return $prompt."\n\n## The brief\n\n".($brief !== '' ? $brief : 'The student hasn\'t shared the brief. Plan it from what a '.strtolower($assignment->kindLabel()).' called "'.$assignment->title.'" in '.$workspace->name.' usually asks, keep to the basics, and say at the end that it is a general plan to check against the real brief.')."\n";
    }

    /**
     * The parts, steps and criteria in an AI's reply, ready to look at. Anything that isn't a mark is ignored;
     * text is cleaned and cut to a title's length, and an item without text is left out.
     *
     * @return array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>, milestones: list<array{title: string, due_on: ?string}>}
     */
    public static function read(string $reply): array
    {
        $parts = [];
        $rest = (string) preg_replace_callback('/<part\b([^>]*)>(.*?)<\/part\s*>/si', function (array $match) use (&$parts) {
            $title = self::clean(self::attributes($match[1])['title'] ?? '');
            if ($title !== '') {
                $parts[] = ['title' => $title, 'marks' => self::marks(self::attributes($match[1])['marks'] ?? null), 'steps' => self::steps($match[2])];
            }

            return '';
        }, $reply);

        $criteria = [];
        $rest = (string) preg_replace_callback('/<criterion\b([^>]*)>(.*?)<\/criterion\s*>/si', function (array $match) use (&$criteria) {
            $title = self::clean($match[2]);
            if ($title !== '') {
                $criteria[] = ['title' => $title, 'marks' => self::marks(self::attributes($match[1])['marks'] ?? null)];
            }

            return '';
        }, $rest);

        $milestones = [];
        $rest = (string) preg_replace_callback('/<milestone\b([^>]*)>(.*?)<\/milestone\s*>/si', function (array $match) use (&$milestones) {
            $title = self::clean($match[2]);
            if ($title !== '') {
                $milestones[] = ['title' => $title, 'due_on' => self::date(self::attributes($match[1])['date'] ?? null)];
            }

            return '';
        }, $rest);

        return ['parts' => $parts, 'steps' => self::steps($rest), 'criteria' => $criteria, 'milestones' => $milestones];
    }

    /** @return list<string> */
    private static function steps(string $text): array
    {
        preg_match_all('/<step\b[^>]*>(.*?)<\/step\s*>/si', $text, $found);

        return array_values(array_filter(array_map(self::clean(...), $found[1]), fn (string $step) => $step !== ''));
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($text, 0, Plans::MAX_TITLE);
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $source, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }

        return $attributes;
    }

    /** A date as Y-m-d, or null when it isn't one. */
    private static function date(?string $value): ?string
    {
        $parsed = $value === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $parsed !== false && $parsed->format('Y-m-d') === trim((string) $value) ? $parsed->format('Y-m-d') : null;
    }

    /** 40, "40" and "40%" are 40; anything else, or out of 1 to 100, is no marks. */
    private static function marks(?string $value): ?int
    {
        if ($value === null || preg_match('/^\s*(\d{1,3})\s*%?\s*$/', $value, $match) !== 1) {
            return null;
        }

        return $match[1] >= 1 && $match[1] <= 100 ? (int) $match[1] : null;
    }
}
