<?php

namespace App\Study;

/**
 * Reads the marks a tutor adds to its replies (resources/prompts/tutor.md,
 * docs/specs/study-memory.md §4.4) out of pasted chat text. It copes with
 * the ways chats get copied: marks in backticks or code blocks, escaped
 * angle brackets and quotes, curly quotes, and the same reply pasted twice.
 * It only reads; nothing is saved here.
 */
final class Capture
{
    public const KINDS = ['finding', 'question', 'flashcard', 'attempt', 'checkpoint', 'summary', 'status'];

    /** The longest each field may be, in characters; longer text is cut. */
    public const LIMITS = [
        'finding' => Findings::MAX_TEXT,
        'question' => Questions::MAX_TEXT,
        'front' => 500,
        'back' => 1000,
        'asked' => 1000,
        'answer' => 2000,
        'checkpoint' => 1000,
        'summary' => 2000,
        'why' => 500,
        'topic' => Topics::MAX_NAME,
    ];

    public const RESULTS = ['correct', 'partial', 'incorrect'];

    public const FORMS = ['recall', 'explain', 'apply', 'recognise'];

    public const STATUSES = ['covered', 'understood', 'confused'];

    /**
     * The marks in the text, in order, without repeats. Each has `kind`,
     * `topic` (the name the tutor gave, or null) and its fields, and a
     * `fingerprint` that names its content.
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $text): array
    {
        $text = self::prepare($text);
        $kinds = implode('|', self::KINDS);
        preg_match_all('/<('.$kinds.')\b([^>]*)>(.*?)<\/\1\s*>/si', $text, $matches, PREG_SET_ORDER);

        $items = [];
        $seen = [];
        foreach ($matches as [, $kind, $attributes, $inner]) {
            $item = self::item(strtolower($kind), self::attributes($attributes), $inner);
            if ($item === null) {
                continue;
            }
            $item['fingerprint'] = self::fingerprint($item);
            if (! isset($seen[$item['fingerprint']])) {
                $seen[$item['fingerprint']] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Names a mark's content, so the same mark pasted again is recognised. */
    public static function fingerprint(array $item): string
    {
        $norm = fn (?string $s) => mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $s)));
        $parts = match ($item['kind']) {
            'flashcard' => [$item['front'] ?? '', $item['back'] ?? ''],
            'attempt' => [$item['asked'] ?? '', $item['answer'] ?? ''],
            'status' => [$item['topic'] ?? '', $item['proposed'] ?? ''],
            default => [$item['text'] ?? ''],
        };

        return sha1($item['kind'].'|'.implode('|', array_map($norm, $parts)));
    }

    /** Undoes what copying does to the marks. */
    private static function prepare(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr($text, ['“' => '"', '”' => '"', '„' => '"', '‘' => "'", '’' => "'"]);
        // Markdown escapes (\<finding) and backticks around marks.
        $tags = implode('|', [...self::KINDS, 'front', 'back', 'asked', 'answer']);
        $text = (string) preg_replace('/\\\\(<\/?(?:'.$tags.')\b)/i', '$1', $text);
        $text = (string) preg_replace('/`+(<(?:'.$tags.')\b)/i', '$1', $text);

        return (string) preg_replace('/(<\/(?:'.$tags.')\s*>)`+/i', '$1', $text);
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $source, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = trim($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
        }

        return $attributes;
    }

    private static function item(string $kind, array $attributes, string $inner): ?array
    {
        $topic = self::clean($attributes['topic'] ?? '', 'topic');
        $topic = $topic === '' ? null : $topic;

        switch ($kind) {
            case 'finding':
            case 'question':
            case 'checkpoint':
            case 'summary':
                $text = self::clean($inner, $kind);

                return $text === '' ? null : ['kind' => $kind, 'topic' => in_array($kind, ['checkpoint', 'summary'], true) ? null : $topic, 'text' => $text];
            case 'flashcard':
                $front = self::clean(self::part($inner, 'front'), 'front');
                $back = self::clean(self::part($inner, 'back'), 'back');

                return $front === '' || $back === '' ? null : ['kind' => $kind, 'topic' => $topic, 'front' => $front, 'back' => $back];
            case 'attempt':
                $asked = self::clean(self::part($inner, 'asked'), 'asked');
                $answer = self::clean(self::part($inner, 'answer'), 'answer');
                if ($asked === '') {
                    // An older or looser mark: the whole text says what was asked and answered.
                    $asked = self::clean($inner, 'asked');
                }
                $result = strtolower($attributes['result'] ?? '');
                $result = ['right' => 'correct', 'wrong' => 'incorrect', 'partly' => 'partial', 'partially' => 'partial'][$result] ?? $result;
                $form = strtolower($attributes['form'] ?? '');
                $support = strtolower($attributes['support'] ?? '');

                return $asked === '' ? null : [
                    'kind' => $kind, 'topic' => $topic, 'asked' => $asked, 'answer' => $answer,
                    'result' => in_array($result, self::RESULTS, true) ? $result : 'unjudged',
                    'form' => in_array($form, self::FORMS, true) ? $form : null,
                    'support' => $support === 'hinted' ? 'hinted' : 'unaided',
                ];
            case 'status':
                $proposed = strtolower($attributes['proposed'] ?? '');

                return $topic === null || ! in_array($proposed, self::STATUSES, true) ? null
                    : ['kind' => $kind, 'topic' => $topic, 'proposed' => $proposed, 'why' => self::clean($inner, 'why')];
        }

        return null;
    }

    private static function part(string $inner, string $tag): string
    {
        return preg_match('/<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'\s*>/si', $inner, $match) === 1 ? $match[1] : '';
    }

    /** Plain text: no mark tags, spaces collapsed, cut to the field's limit. */
    private static function clean(string $text, string $field): string
    {
        // Only the marks' own tags go: "age<18" in an answer is content.
        $tags = implode('|', [...self::KINDS, 'front', 'back', 'asked', 'answer']);
        $text = (string) preg_replace('/<\/?(?:'.$tags.')\b[^>]*>/i', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $limit = self::LIMITS[$field];

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}
