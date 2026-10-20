<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * The reply the tutor recommends the student send next.
 *
 * The model ends its message with <suggest>…</suggest>. The chat hides
 * that line and offers the words as the box's placeholder. Tab accepts
 * them. The tag stays in what is stored, so the next turn still sees it.
 */
final class SuggestedReply
{
    public const MAX = 160;

    /** The reply so far, with a suggestion line that is still being written cut off. */
    public static function hide(string $text): string
    {
        $at = stripos($text, '<suggest');

        return $at === false ? $text : substr($text, 0, $at);
    }

    /**
     * The message without its suggestion, and the suggestion itself.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $text): array
    {
        $suggestion = '';
        if (preg_match('/<suggest\b[^>]*>(.*?)<\/suggest>/si', $text, $match) === 1) {
            $suggestion = self::clean($match[1]);
            $text = str_replace($match[0], '', $text);
        }
        $text = (string) preg_replace('/<suggest\b[^>]*>.*$/si', '', $text);

        return [trim($text), $suggestion];
    }

    private static function clean(string $raw): string
    {
        $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));
        $text = trim($text, " \t\n\r\0\x0B\"'“”");
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > self::MAX) {
            $text = rtrim(mb_substr($text, 0, self::MAX - 1)).'…';
        }

        return $text;
    }
}
