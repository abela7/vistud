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

    private const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'taskList', 'codeBlock', 'horizontalRule', 'table', 'callout', 'image', 'pageBreak', 'blockMath'];

    private const INLINE = ['text', 'hardBreak', 'inlineMath'];

    /** A formula's LaTeX, at most. */
    public const MAX_FORMULA = 5_000;

    /** How a text run's marks nest in Markdown: code innermost, the link round everything. */
    private const MARK_ORDER = ['code' => 0, 'bold' => 1, 'italic' => 2, 'strike' => 3, 'highlight' => 4, 'underline' => 5, 'subscript' => 6, 'superscript' => 7, 'link' => 8];

    /** Set while plain() runs: no Markdown marks, just the words. */
    private static bool $plain = false;

    /** What each node may hold. */
    private const CHILDREN = [
        'doc' => self::BLOCKS,
        'paragraph' => self::INLINE,
        'heading' => self::INLINE,
        'blockquote' => self::BLOCKS,
        'callout' => self::BLOCKS,
        'image' => [],
        'pageBreak' => [],
        'inlineMath' => [],
        'blockMath' => [],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCKS,
        'taskList' => ['taskItem'],
        'taskItem' => self::BLOCKS,
        'codeBlock' => ['text'],
        'horizontalRule' => [],
        'table' => ['tableRow'],
        'tableRow' => ['tableHeader', 'tableCell'],
        'tableHeader' => self::BLOCKS,
        'tableCell' => self::BLOCKS,
        'hardBreak' => [],
        'text' => [],
    ];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight', 'subscript', 'superscript'];

    /** A highlight's tone: a name the themes draw, never a colour value. The first is the default. */
    public const HIGHLIGHTS = ['yellow', 'green', 'blue', 'pink', 'purple'];

    private const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** An empty note: one empty paragraph. */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /** The cleaned document, or a 422 on the `doc` field. */
    public static function clean(mixed $doc): array
    {
        // As stored (App\Study\Notes): letters outside ASCII count as their UTF-8 bytes, not as \u escapes.
        $bytes = strlen((string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
            } elseif (in_array($node['type'] ?? null, ['inlineMath', 'blockMath'], true)) {
                $words[] = $node['attrs']['latex'] ?? '';
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($doc);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $words)));
    }

    /**
     * The note as Markdown (GitHub's flavour), for a study session's briefing
     * and for a download other apps can read: headings, paragraphs, lists
     * (tasks ticked or not), quotes, code, tables, callouts (> [!NOTE]),
     * pictures and formulas ($…$) keep their shape, and bold, italic, links
     * and the other marks keep their markers.
     */
    public static function markdown(array $doc): string
    {
        $lines = self::blocks($doc['content'] ?? [], '');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** The note as plain text for a .txt file: the same shape, without Markdown's markers. */
    public static function plain(array $doc): string
    {
        self::$plain = true;
        try {
            return self::markdown($doc);
        } finally {
            self::$plain = false;
        }
    }

    /** @return list<string> */
    private static function blocks(array $nodes, string $indent): array
    {
        $lines = [];
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            $inline = fn (array $n) => self::inline($n['content'] ?? []);
            switch ($type) {
                case 'heading':
                    $lines[] = $indent.(self::$plain ? '' : str_repeat('#', max(1, min(6, (int) ($node['attrs']['level'] ?? 2)))).' ').$inline($node);
                    $lines[] = '';
                    break;
                case 'paragraph':
                    $text = $inline($node);
                    if ($text !== '') {
                        $lines[] = $indent.$text;
                        $lines[] = '';
                    }
                    break;
                case 'blockquote':
                    $inner = self::blocks($node['content'] ?? [], '');
                    while ($inner !== [] && end($inner) === '') {
                        array_pop($inner);
                    }
                    foreach ($inner as $line) {
                        $lines[] = $indent.($line === '' ? '>' : '> '.$line);
                    }
                    $lines[] = '';
                    break;
                case 'bulletList':
                case 'orderedList':
                case 'taskList':
                    foreach ($node['content'] ?? [] as $i => $item) {
                        $marker = match ($type) {
                            'orderedList' => ($i + 1).'.',
                            'taskList' => ($item['attrs']['checked'] ?? false) ? '- [x]' : '- [ ]',
                            default => '-',
                        };
                        $inner = array_values(array_filter(self::blocks($item['content'] ?? [], ''), fn ($line) => $line !== ''));
                        $lines[] = $indent.$marker.' '.($inner[0] ?? '');
                        foreach (array_slice($inner, 1) as $line) {
                            $lines[] = $indent.'  '.$line;
                        }
                    }
                    $lines[] = '';
                    break;
                case 'codeBlock':
                    $fence = self::$plain ? [] : [$indent.'```'];
                    $lines = [...$lines, ...$fence];
                    foreach (explode("\n", $inline($node)) as $line) {
                        $lines[] = $indent.$line;
                    }
                    $lines = [...$lines, ...$fence, ''];
                    break;
                case 'horizontalRule':
                    $lines[] = $indent.'---';
                    $lines[] = '';
                    break;
                case 'table':
                    foreach ($node['content'] ?? [] as $i => $row) {
                        $cells = array_map(
                            fn (array $cell) => str_replace(['|', "\n"], ['\\|', ' '], trim(implode(' ', array_filter(self::blocks($cell['content'] ?? [], ''), fn ($l) => $l !== '')))),
                            $row['content'] ?? [],
                        );
                        $lines[] = $indent.'| '.implode(' | ', $cells).' |';
                        if ($i === 0) {
                            $lines[] = $indent.'|'.str_repeat(' --- |', max(1, count($cells)));
                        }
                    }
                    $lines[] = '';
                    break;
                case 'callout':
                    $tone = strtoupper($node['attrs']['tone'] ?? 'NOTE');
                    $inner = self::blocks($node['content'] ?? [], '');
                    while ($inner !== [] && end($inner) === '') {
                        array_pop($inner);
                    }
                    if (self::$plain) {
                        $lines[] = $indent.ucfirst(strtolower($tone)).':';
                        $lines = [...$lines, ...array_map(fn ($line) => $indent.$line, $inner), ''];
                        break;
                    }
                    $lines[] = $indent."> [!{$tone}]";
                    foreach ($inner as $line) {
                        $lines[] = $indent.($line === '' ? '>' : '> '.$line);
                    }
                    $lines[] = '';
                    break;
                case 'image':
                    $src = $node['attrs']['src'] ?? '';
                    $alt = $node['attrs']['alt'] ?? 'Image';
                    if ($src !== '') {
                        $lines[] = $indent.(self::$plain ? "[Picture: {$alt}]" : "![{$alt}]({$src})");
                        $lines[] = '';
                    }
                    break;
                case 'pageBreak':
                    $lines[] = $indent.'---';
                    $lines[] = '';
                    break;
                case 'blockMath':
                    $latex = (string) ($node['attrs']['latex'] ?? '');
                    if ($latex !== '') {
                        $fence = self::$plain ? [] : [$indent.'$$'];
                        $lines = [...$lines, ...$fence, ...array_map(fn ($line) => $indent.$line, explode("\n", $latex)), ...$fence, ''];
                    }
                    break;
            }
        }

        return $lines;
    }

    private static function inline(array $nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $text .= match ($node['type'] ?? null) {
                'text' => self::marked((string) ($node['text'] ?? ''), is_array($node['marks'] ?? null) ? $node['marks'] : []),
                'hardBreak' => "\n",
                'inlineMath' => self::$plain ? (string) ($node['attrs']['latex'] ?? '') : '$'.($node['attrs']['latex'] ?? '').'$',
                default => '',
            };
        }

        return trim($text);
    }

    /**
     * A run of text with its marks as Markdown: **bold**, *italic*, ~~struck~~,
     * `code`, ==highlighted== and [words](address); underline, subscript and
     * superscript as the HTML Markdown allows. Spaces at the ends stay outside
     * the markers, or a reader wouldn't take them as markers.
     */
    private static function marked(string $text, array $marks): string
    {
        if (self::$plain || $text === '' || $marks === []) {
            return $text;
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);
        [$lead, $core, $tail] = [$m[1], $m[2], $m[3]];
        if ($core === '') {
            return $text;
        }
        usort($marks, fn ($a, $b) => (self::MARK_ORDER[$a['type'] ?? ''] ?? 9) <=> (self::MARK_ORDER[$b['type'] ?? ''] ?? 9));
        foreach ($marks as $mark) {
            $core = match ($mark['type'] ?? null) {
                'code' => '`'.$core.'`',
                'bold' => '**'.$core.'**',
                'italic' => '*'.$core.'*',
                'strike' => '~~'.$core.'~~',
                'highlight' => '=='.$core.'==',
                'underline' => '<u>'.$core.'</u>',
                'subscript' => '<sub>'.$core.'</sub>',
                'superscript' => '<sup>'.$core.'</sup>',
                'link' => '['.$core.']('.($mark['attrs']['href'] ?? '').')',
                default => $core,
            };
        }

        return $lead.$core.$tail;
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
        if ($type === 'image' && ! isset($attrs['src'])) {
            // A picture from somewhere a note can't keep (another app's clipboard, a data: address) is left out.
            return null;
        }
        if (in_array($type, ['inlineMath', 'blockMath'], true) && ! isset($attrs['latex'])) {
            // An empty formula shows nothing: it goes.
            return null;
        }
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
        if (in_array($type, [...self::BLOCKS, 'listItem', 'taskItem', 'tableRow', 'tableHeader', 'tableCell'], true) && is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $kept['id'] = $id;
        }
        $align = $attrs['textAlign'] ?? null;
        if (in_array($type, ['paragraph', 'heading'], true) && in_array($align, self::ALIGNMENTS, true) && $align !== 'left') {
            $kept['textAlign'] = $align;
        }

        switch ($type) {
            case 'doc':
                // How the note is shown: A4 pages or full width (resources/js/note/editor.js). Unset, the student's own choice.
                $view = $attrs['view'] ?? null;
                if (in_array($view, ['pages', 'continuous'], true)) {
                    $kept['view'] = $view;
                }
                break;
            case 'heading':
                $level = $attrs['level'] ?? null;
                $kept['level'] = is_int($level) && $level >= 1 && $level <= 4 ? $level : 2;
                break;
            case 'tableHeader':
            case 'tableCell':
                foreach (['colspan', 'rowspan'] as $span) {
                    $value = $attrs[$span] ?? 1;
                    $kept[$span] = is_int($value) && $value >= 1 && $value <= 100 ? $value : 1;
                }
                $widths = $attrs['colwidth'] ?? null;
                $kept['colwidth'] = is_array($widths) && $widths !== [] && count($widths) <= 100 && array_filter($widths, fn ($w) => ! is_int($w) || $w < 1 || $w > 5000) === [] ? array_values($widths) : null;
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
            case 'callout':
                $tone = $attrs['tone'] ?? null;
                $kept['tone'] = in_array($tone, ['theorem', 'definition', 'formula', 'example', 'note'], true) ? $tone : 'note';
                break;
            case 'inlineMath':
            case 'blockMath':
                // LaTeX, drawn by KaTeX in the browser; it is never run as anything.
                $latex = $attrs['latex'] ?? null;
                if (is_string($latex) && trim($latex) !== '') {
                    if (mb_strlen($latex) > self::MAX_FORMULA) {
                        Input::refuse(['doc' => 'A formula in this note is too long to save. Split it into smaller ones.']);
                    }
                    $kept['latex'] = trim($latex);
                }
                break;
            case 'image':
                $src = $attrs['src'] ?? null;
                // A web address, or a picture kept for the note (App\Http\Controllers\Api\V1\NoteImageController).
                if (is_string($src) && strlen($src) <= 2048 && preg_match('#^(https?://[^\s"<>]+|/notes/images/[A-Za-z0-9-]{1,64})$#i', $src)) {
                    $kept['src'] = $src;
                }
                $alt = $attrs['alt'] ?? null;
                if (is_string($alt)) {
                    $kept['alt'] = mb_substr($alt, 0, 500);
                }
                $title = $attrs['title'] ?? null;
                if (is_string($title)) {
                    $kept['title'] = mb_substr($title, 0, 500);
                }
                $width = $attrs['width'] ?? null;
                if (is_string($width) && preg_match('/^(100%|75%|50%|33%|25%|[1-9][0-9]{1,3}px)$/', $width)) {
                    $kept['width'] = $width;
                }
                $align = $attrs['align'] ?? null;
                if (in_array($align, ['left', 'center', 'right'], true)) {
                    $kept['align'] = $align;
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
            if ($type === 'highlight') {
                $tone = $mark['attrs']['tone'] ?? null;
                $kept[] = ['type' => 'highlight', 'attrs' => ['tone' => in_array($tone, self::HIGHLIGHTS, true) ? $tone : self::HIGHLIGHTS[0]]];

                continue;
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
