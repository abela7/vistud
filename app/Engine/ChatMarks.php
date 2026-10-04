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

    /**
     * A reply still being written, cut before a mark that hasn't closed yet (and a tag cut in the middle), so the
     * chat never shows raw tags while the words stream in; the finished reply shows the mark as a quote.
     */
    public static function partial(string $text): string
    {
        $kinds = implode('|', Capture::KINDS);
        if (preg_match_all('/<('.$kinds.')\b/i', $text, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = array_key_last($m[0]);
            [$open, $at] = [strtolower($m[1][$last][0]), $m[0][$last][1]];
            if (preg_match('/<\/'.$open.'\s*>/i', substr($text, $at)) !== 1) {
                $text = substr($text, 0, $at);
            }
        }

        return (string) preg_replace('/<\/?[a-z]*$/i', '', $text);
    }

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
