<?php

namespace App\Study;

/**
 * Real-time document editor for student notes.
 * Performs in-place text replacement, insertions, prepending, appending,
 * and section edits while preserving document structure and marks wherever possible.
 */
final class NoteEditor
{
    /**
     * Finds and replaces text inside a note document.
     * Tries AST-level node replacement first to preserve document structure,
     * then falls back to Markdown-level replacement if the search spans across nodes or marks.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function replaceText(array $doc, string $search, string $replace, bool $all = false): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['doc' => NoteDoc::clean($doc), 'count' => 0];
        }

        $cleanedDoc = NoteDoc::clean($doc);

        // 1. Try AST-level text replacement in text nodes and math nodes
        $count = 0;
        $astDoc = $cleanedDoc;
        $astWalk = function (array &$node) use (&$astWalk, &$count, $search, $replace, $all) {
            if (($node['type'] ?? null) === 'text' && isset($node['text']) && is_string($node['text'])) {
                if (str_contains($node['text'], $search)) {
                    if ($all) {
                        $occ = substr_count($node['text'], $search);
                        $node['text'] = str_replace($search, $replace, $node['text']);
                        $count += $occ;
                    } else {
                        if ($count === 0) {
                            $pos = strpos($node['text'], $search);
                            $node['text'] = substr_replace($node['text'], $replace, $pos, strlen($search));
                            $count++;
                        }
                    }
                }
            } elseif (in_array($node['type'] ?? null, ['inlineMath', 'blockMath'], true) && isset($node['attrs']['latex']) && is_string($node['attrs']['latex'])) {
                if (str_contains($node['attrs']['latex'], $search)) {
                    if ($all || $count === 0) {
                        $node['attrs']['latex'] = str_replace($search, $replace, $node['attrs']['latex']);
                        $count++;
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                for ($i = 0; $i < count($node['content']); $i++) {
                    if (! $all && $count > 0) {
                        break;
                    }
                    $astWalk($node['content'][$i]);
                }
            }
        };

        $astWalk($astDoc);
        if ($count > 0) {
            return ['doc' => NoteDoc::clean($astDoc), 'count' => $count];
        }

        // 2. Try case-insensitive AST match
        $caseWalk = function (array &$node) use (&$caseWalk, &$count, $search, $replace, $all) {
            if (($node['type'] ?? null) === 'text' && isset($node['text']) && is_string($node['text'])) {
                if (stripos($node['text'], $search) !== false) {
                    if ($all) {
                        $node['text'] = (string) str_ireplace($search, $replace, $node['text']);
                        $count++;
                    } else {
                        if ($count === 0) {
                            $pos = stripos($node['text'], $search);
                            $node['text'] = substr_replace($node['text'], $replace, $pos, strlen($search));
                            $count++;
                        }
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                for ($i = 0; $i < count($node['content']); $i++) {
                    if (! $all && $count > 0) {
                        break;
                    }
                    $caseWalk($node['content'][$i]);
                }
            }
        };

        $caseWalk($astDoc);
        if ($count > 0) {
            return ['doc' => NoteDoc::clean($astDoc), 'count' => $count];
        }

        // 3. Fall back to Markdown-level replacement (multi-line or spanning marks)
        $markdown = NoteDoc::markdown($cleanedDoc);
        if (str_contains($markdown, $search)) {
            $newMarkdown = $all
                ? str_replace($search, $replace, $markdown)
                : self::replaceFirst($search, $replace, $markdown);

            return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => MarkdownDoc::blocks($newMarkdown)]), 'count' => 1];
        }

        // 4. Try normalized quotes & whitespace in Markdown
        $normSearch = self::normalizeText($search);
        $normMd = self::normalizeText($markdown);
        if (str_contains($normMd, $normSearch)) {
            $newMarkdown = $all
                ? str_replace($normSearch, $replace, $normMd)
                : self::replaceFirst($normSearch, $replace, $normMd);

            return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => MarkdownDoc::blocks($newMarkdown)]), 'count' => 1];
        }

        return ['doc' => $cleanedDoc, 'count' => 0];
    }

    /**
     * Inserts text after the block or line containing $search.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function insertAfter(array $doc, string $search, string $text): array
    {
        $cleanedDoc = NoteDoc::clean($doc);
        $search = trim($search);
        if ($search === '' || trim($text) === '') {
            return ['doc' => $cleanedDoc, 'count' => 0];
        }

        $content = $cleanedDoc['content'] ?? [];
        $blocks = MarkdownDoc::blocks($text);

        // Find matching block
        foreach ($content as $idx => $block) {
            $blockText = NoteDoc::text(['type' => 'doc', 'content' => [$block]]);
            if (str_contains($blockText, $search) || stripos($blockText, $search) !== false) {
                array_splice($content, $idx + 1, 0, $blocks);

                return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => $content]), 'count' => 1];
            }
        }

        // Fall back to Markdown insertion
        $markdown = NoteDoc::markdown($cleanedDoc);
        if (str_contains($markdown, $search)) {
            $pos = strpos($markdown, $search) + strlen($search);
            $newMarkdown = substr($markdown, 0, $pos)."\n\n".$text."\n\n".substr($markdown, $pos);

            return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => MarkdownDoc::blocks($newMarkdown)]), 'count' => 1];
        }

        return ['doc' => $cleanedDoc, 'count' => 0];
    }

    /**
     * Inserts text before the block or line containing $search.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function insertBefore(array $doc, string $search, string $text): array
    {
        $cleanedDoc = NoteDoc::clean($doc);
        $search = trim($search);
        if ($search === '' || trim($text) === '') {
            return ['doc' => $cleanedDoc, 'count' => 0];
        }

        $content = $cleanedDoc['content'] ?? [];
        $blocks = MarkdownDoc::blocks($text);

        foreach ($content as $idx => $block) {
            $blockText = NoteDoc::text(['type' => 'doc', 'content' => [$block]]);
            if (str_contains($blockText, $search) || stripos($blockText, $search) !== false) {
                array_splice($content, $idx, 0, $blocks);

                return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => $content]), 'count' => 1];
            }
        }

        $markdown = NoteDoc::markdown($cleanedDoc);
        if (str_contains($markdown, $search)) {
            $pos = strpos($markdown, $search);
            $newMarkdown = substr($markdown, 0, $pos)."\n\n".$text."\n\n".substr($markdown, $pos);

            return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => MarkdownDoc::blocks($newMarkdown)]), 'count' => 1];
        }

        return ['doc' => $cleanedDoc, 'count' => 0];
    }

    /**
     * Prepends blocks to the beginning of the note document.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function prepend(array $doc, string $text): array
    {
        $cleanedDoc = NoteDoc::clean($doc);
        $blocks = MarkdownDoc::blocks($text);
        if ($blocks === []) {
            return ['doc' => $cleanedDoc, 'count' => 0];
        }

        $content = array_merge($blocks, $cleanedDoc['content'] ?? []);

        return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => $content]), 'count' => 1];
    }

    /**
     * Appends blocks to the end of the note document.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function append(array $doc, string $text): array
    {
        $cleanedDoc = NoteDoc::clean($doc);
        $blocks = MarkdownDoc::blocks($text);
        if ($blocks === []) {
            return ['doc' => $cleanedDoc, 'count' => 0];
        }

        $content = $cleanedDoc['content'] ?? [];
        if (trim(NoteDoc::text($cleanedDoc)) === '' && count($content) <= 1) {
            $content = [];
        }

        return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => array_merge($content, $blocks)]), 'count' => 1];
    }

    /**
     * Replaces all content in the note with the provided text.
     *
     * @param  array{type?: string, content?: list<array<string, mixed>>}  $doc
     * @return array{doc: array{type: string, content: list<array<string, mixed>>}, count: int}
     */
    public static function replaceAll(array $doc, string $text): array
    {
        $blocks = MarkdownDoc::blocks($text);
        if ($blocks === []) {
            $blocks = [['type' => 'paragraph']];
        }

        return ['doc' => NoteDoc::clean(['type' => 'doc', 'content' => $blocks]), 'count' => 1];
    }

    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }

        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    private static function normalizeText(string $text): string
    {
        $str = str_replace(["\r\n", "\r"], "\n", $text);
        $str = str_replace(['“', '”'], '"', $str);

        return str_replace(['‘', '’'], "'", $str);
    }
}
