<?php

namespace App\Engine;

use App\Study\Capture;

/**
 * The tutor's marks as the chat shows them (docs/specs/study-memory.md §6): a `<finding topic="Joins">…</finding>`
 * in a reply becomes a small labelled quote ("Key point · Joins") instead of raw tags, so the student sees what
 * the tutor offers to keep. The write-back still reads the marks from the reply's own text.
 */
final class ChatMarks
{
    private const WORDS = ['summary' => 'Summary', 'finding' => 'Key point', 'question' => 'Question', 'flashcard' => 'Flashcard', 'attempt' => 'Your answer', 'checkpoint' => 'Where we are', 'status' => 'Status'];

    /** The reply as Markdown with each mark turned into a labelled quote. */
    public static function present(string $text): string
    {
        $kinds = implode('|', Capture::KINDS);

        return (string) preg_replace_callback('/<('.$kinds.')\b([^>]*)>(.*?)<\/\1\s*>/si', function (array $m) {
            $items = Capture::parse($m[0]);
            if ($items === []) {
                return '';
            }
            $item = $items[0];
            $label = self::WORDS[$item['kind']].($item['topic'] !== null ? ' · '.$item['topic'] : '');
            $lines = match ($item['kind']) {
                'flashcard' => ['**Front:** '.$item['front'], '**Back:** '.$item['back']],
                'attempt' => array_values(array_filter([
                    '**Asked:** '.$item['asked'],
                    $item['answer'] !== '' ? '**You said:** '.$item['answer'] : null,
                    '**Result:** '.['correct' => 'right', 'partial' => 'partly right', 'incorrect' => 'not yet', 'unjudged' => 'not marked'][$item['result']],
                ])),
                'status' => array_values(array_filter(['Proposed: **'.$item['proposed'].'**', $item['why'] !== '' ? $item['why'] : null])),
                default => [$item['text']],
            };

            return "\n\n> **{$label}**\n> ".implode("\n> ", array_map(fn (string $line) => str_replace("\n", ' ', $line), $lines))."\n\n";
        }, $text);
    }
}
