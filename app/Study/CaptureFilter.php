<?php

namespace App\Study;

/**
 * What is never captured from an outside chat (ADR 0002 §11; docs/specs/mcp-connections-plan.md §3.6): passwords,
 * keys, tokens and connection strings. A write carrying one is refused as `sensitive` before any service runs, so
 * nothing of it reaches the journal, a note or a card.
 *
 * Wellbeing, health, other people's personal data and venting are also never captured (§11), but they can't be told
 * apart by a pattern; the MCP rules tell the model not to save them, and the student reviews what was saved.
 */
final class CaptureFilter
{
    /** @var list<string> */
    private const SECRETS = [
        '/\b(?:password|passwd|passcode|pwd)\s*(?:is|[:=])\s*\S+/i',
        '/\b(?:sk|pk|rk)-[A-Za-z0-9_-]{16,}/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b(?:mysql|postgres(?:ql)?|mongodb(?:\+srv)?|redis):\/\/[^\s:@\/]+:[^\s@\/]+@/i',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]{20,}=*/',
    ];

    /**
     * Null when the input may be captured, or the reason it may not.
     *
     * @param  array<mixed>  $input
     */
    public static function reason(array $input): ?string
    {
        foreach (self::strings($input) as $text) {
            foreach (self::SECRETS as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return 'sensitive';
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $input
     * @return list<string>
     */
    private static function strings(array $input): array
    {
        $out = [];
        array_walk_recursive($input, function (mixed $value) use (&$out): void {
            if (is_string($value)) {
                $out[] = $value;
            }
        });

        return $out;
    }
}
