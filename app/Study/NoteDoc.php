<?php

namespace App\Study;

/**
 * Checks a note's document (the editor's JSON, ADR 0003 §5.1) before it is
 * stored, and returns it cleaned. Only the node types, marks and attributes
 * the editor supports get through: an unknown node is refused, unknown
 * attributes are dropped, and a link that isn't http, https or mailto loses
 * its link. The browser never gets back anything the editor can't show.
 */
final class NoteDoc
{
    public const MAX_BYTES = 2_000_000;

    private const MAX_DEPTH = 40;

    private const MAX_NODES = 100_000;

    private const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule'];

    private const INLINE = ['text', 'hardBreak'];

    /** What each node may hold. */
    private const CHILDREN = [
        'doc' => self::BLOCKS,
        'paragraph' => self::INLINE,
        'heading' => self::INLINE,
        'blockquote' => self::BLOCKS,
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCKS,
        'taskList' => ['taskItem'],
        'taskItem' => self::BLOCKS,
        'codeBlock' => ['text'],
        'horizontalRule' => [],
        'hardBreak' => [],
        'text' => [],
    ];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link'];

    /** An empty note: one empty paragraph. */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /** The cleaned document, or a 422 on the `doc` field. */
    public static function clean(mixed $doc): array
    {
        $bytes = strlen((string) json_encode($doc));
        if ($bytes > self::MAX_BYTES) {
            Input::refuse(['doc' => 'This note is too long to save. Split it into two notes.']);
        }
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            Input::refuse(['doc' => 'This note has content the editor doesn\'t support.']);
        }

        $count = 0;
        $cleaned = self::node($doc, 'doc', 0, $count);
        if ($cleaned === null) {
            Input::refuse(['doc' => 'This note has content the editor doesn\'t support.']);
        }
        if (($cleaned['content'] ?? []) === []) {
            $cleaned['content'] = [['type' => 'paragraph']];
        }

        return $cleaned;
    }

    /** The note's words, for listings and later search. */
    public static function text(array $doc): string
    {
        $words = [];
        $walk = function (array $node) use (&$walk, &$words) {
            if (($node['type'] ?? null) === 'text') {
                $words[] = $node['text'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($doc);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $words)));
    }

    /** One node, cleaned; null when it has to be dropped (an empty text). Refuses what can't be cleaned. */
    private static function node(mixed $node, string $expected, int $depth, int &$count): ?array
    {
        $type = is_array($node) ? ($node['type'] ?? null) : null;
        if (! is_string($type) || ! isset(self::CHILDREN[$type]) || ($expected !== 'doc' && $type === 'doc') || ++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            Input::refuse(['doc' => 'This note has content the editor doesn\'t support.']);
        }

        if ($type === 'text') {
            $text = $node['text'] ?? null;
            if (! is_string($text) || $text === '') {
                return null;
            }
            $marks = self::marks($node['marks'] ?? []);

            return ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
        }

        $cleaned = ['type' => $type];
        $attrs = self::attrs($type, is_array($node['attrs'] ?? null) ? $node['attrs'] : []);
        if ($attrs !== []) {
            $cleaned['attrs'] = $attrs;
        }

        $content = [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $childType = is_array($child) ? ($child['type'] ?? null) : null;
            if (! in_array($childType, self::CHILDREN[$type], true)) {
                Input::refuse(['doc' => 'This note has content the editor doesn\'t support.']);
            }
            $child = self::node($child, $type, $depth + 1, $count);
            if ($child !== null) {
                // Code is plain text: no bold or links inside it.
                $content[] = $type === 'codeBlock' ? ['type' => 'text', 'text' => $child['text']] : $child;
            }
        }
        if ($content !== []) {
            $cleaned['content'] = $content;
        }

        return $cleaned;
    }

    /** The attributes the editor uses for this node type; the rest are dropped. */
    private static function attrs(string $type, array $attrs): array
    {
        $kept = [];
        $id = $attrs['id'] ?? null;
        if (in_array($type, [...self::BLOCKS, 'listItem', 'taskItem'], true) && is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $kept['id'] = $id;
        }

        switch ($type) {
            case 'heading':
                $level = $attrs['level'] ?? null;
                $kept['level'] = is_int($level) && $level >= 1 && $level <= 3 ? $level : 2;
                break;
            case 'orderedList':
                $start = $attrs['start'] ?? 1;
                $kept['start'] = is_int($start) && $start >= 0 && $start < 100_000 ? $start : 1;
                break;
            case 'taskItem':
                $kept['checked'] = ($attrs['checked'] ?? false) === true;
                break;
            case 'codeBlock':
                $language = $attrs['language'] ?? null;
                if (is_string($language) && preg_match('/^[A-Za-z0-9+#-]{1,32}$/', $language)) {
                    $kept['language'] = $language;
                }
                break;
        }

        return $kept;
    }

    /** @return list<array{type: string, attrs?: array}> */
    private static function marks(mixed $marks): array
    {
        $kept = [];
        foreach (is_array($marks) ? $marks : [] as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! in_array($type, self::MARKS, true)) {
                Input::refuse(['doc' => 'This note has content the editor doesn\'t support.']);
            }
            if ($type === 'link') {
                $href = $mark['attrs']['href'] ?? null;
                if (is_string($href) && preg_match('#^(https?://|mailto:)\S+$#i', trim($href)) && strlen($href) <= 2000) {
                    $kept[] = ['type' => 'link', 'attrs' => ['href' => trim($href)]];
                }

                continue;
            }
            $kept[] = ['type' => $type];
        }

        return array_values(array_unique($kept, SORT_REGULAR));
    }
}
