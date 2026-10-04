<?php

namespace App\Study;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown as a note's blocks (the editor's JSON, ADR 0003 §5.1), for what the tutor writes into a note:
 * headings, paragraphs, lists and checklists, quotes, code (a diagram's code too), tables, rules, formulas
 * ($…$ and $$…$$) and the usual marks. Raw HTML stays as plain text. What comes out still goes through
 * NoteDoc::clean before it is kept.
 */
final class MarkdownDoc
{
    /** @return list<array<string, mixed>> */
    public static function blocks(string $markdown): array
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false, 'max_nesting_level' => 30]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $document = (new MarkdownParser($environment))->parse(str_replace("\r\n", "\n", $markdown));

        return self::children($document);
    }

    /** @return list<array<string, mixed>> */
    private static function children(Node $parent): array
    {
        $blocks = [];
        foreach ($parent->children() as $node) {
            array_push($blocks, ...self::block($node));
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private static function block(Node $node): array
    {
        return match (true) {
            $node instanceof Heading => [['type' => 'heading', 'attrs' => ['level' => max(1, min(4, $node->getLevel()))], 'content' => self::inline($node)]],
            $node instanceof Paragraph => self::paragraph($node),
            $node instanceof BlockQuote => [['type' => 'blockquote', 'content' => self::orEmpty(self::children($node))]],
            $node instanceof ListBlock => [self::list($node)],
            $node instanceof FencedCode => [self::code($node->getLiteral(), $node->getInfoWords()[0] ?? null)],
            $node instanceof IndentedCode => [self::code($node->getLiteral(), null)],
            $node instanceof ThematicBreak => [['type' => 'horizontalRule']],
            $node instanceof Table => [self::table($node)],
            $node instanceof HtmlBlock => [self::text(trim($node->getLiteral()))],
            default => [],
        };
    }

    /**
     * A paragraph, or a formula on a line of its own ($$…$$).
     *
     * @return list<array<string, mixed>>
     */
    private static function paragraph(Paragraph $node): array
    {
        $raw = trim(self::plain($node));
        if (preg_match('/^\$\$(.+)\$\$$/s', $raw, $m) === 1 && trim($m[1]) !== '') {
            return [['type' => 'blockMath', 'attrs' => ['latex' => trim($m[1])]]];
        }
        $inline = self::inline($node);

        return $inline === [] ? [] : [['type' => 'paragraph', 'content' => $inline]];
    }

    /** @return array<string, mixed> */
    private static function list(ListBlock $node): array
    {
        $task = false;
        foreach ($node->children() as $item) {
            $task = $task || self::marker($item) !== null;
        }
        $items = [];
        foreach ($node->children() as $item) {
            if (! $item instanceof ListItem) {
                continue;
            }
            $marker = self::marker($item);
            $marker?->detach();
            $content = self::orEmpty(self::children($item));
            $items[] = $task
                ? ['type' => 'taskItem', 'attrs' => ['checked' => $marker?->isChecked() ?? false], 'content' => $content]
                : ['type' => 'listItem', 'content' => $content];
        }
        if ($task) {
            return ['type' => 'taskList', 'content' => $items];
        }
        $ordered = $node->getListData()->type === ListBlock::TYPE_ORDERED;

        return $ordered
            ? ['type' => 'orderedList', 'attrs' => ['start' => max(1, (int) ($node->getListData()->start ?? 1))], 'content' => $items]
            : ['type' => 'bulletList', 'content' => $items];
    }

    /** A checklist item's box, if it has one. */
    private static function marker(Node $item): ?TaskListItemMarker
    {
        $first = $item->firstChild();
        $marker = $first instanceof Paragraph ? $first->firstChild() : null;

        return $marker instanceof TaskListItemMarker ? $marker : null;
    }

    /** @return array<string, mixed> */
    private static function code(string $literal, ?string $language): array
    {
        $literal = rtrim($literal, "\n");
        $code = ['type' => 'codeBlock'];
        if ($language !== null && preg_match('/^[A-Za-z0-9+#-]{1,32}$/', $language) === 1) {
            $code['attrs'] = ['language' => $language];
        }
        if ($literal !== '') {
            $code['content'] = [['type' => 'text', 'text' => $literal]];
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private static function table(Table $node): array
    {
        $rows = [];
        foreach ($node->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }
            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->children() as $cell) {
                    if ($cell instanceof TableCell) {
                        $inline = self::inline($cell);
                        $cells[] = ['type' => $section->isHead() ? 'tableHeader' : 'tableCell', 'content' => [['type' => 'paragraph'] + ($inline === [] ? [] : ['content' => $inline])]];
                    }
                }
                if ($cells !== []) {
                    $rows[] = ['type' => 'tableRow', 'content' => $cells];
                }
            }
        }

        return ['type' => 'table', 'content' => $rows];
    }

    /**
     * A block's words with their marks; formulas ($…$) become formulas.
     *
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function inline(Node $parent, array $marks = []): array
    {
        $out = [];
        foreach ($parent->children() as $node) {
            $add = match (true) {
                $node instanceof Text => self::words($node->getLiteral(), $marks),
                $node instanceof Code => [self::run($node->getLiteral(), [...$marks, ['type' => 'code']])],
                $node instanceof Strong => self::inline($node, [...$marks, ['type' => 'bold']]),
                $node instanceof Emphasis => self::inline($node, [...$marks, ['type' => 'italic']]),
                $node instanceof Strikethrough => self::inline($node, [...$marks, ['type' => 'strike']]),
                $node instanceof Link => self::inline($node, [...$marks, ['type' => 'link', 'attrs' => ['href' => $node->getUrl()]]]),
                $node instanceof Image => self::words(self::plain($node), $marks),
                $node instanceof Newline => [$node->getType() === Newline::HARDBREAK ? ['type' => 'hardBreak'] : self::run(' ', $marks)],
                $node instanceof HtmlInline => [self::run($node->getLiteral(), $marks)],
                default => self::inline($node, $marks),
            };
            array_push($out, ...$add);
        }

        // Runs with the same marks side by side become one.
        $merged = [];
        foreach ($out as $run) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];
            if ($last !== null && ($last['type'] ?? null) === 'text' && ($run['type'] ?? null) === 'text' && ($last['marks'] ?? []) === ($run['marks'] ?? [])) {
                $merged[array_key_last($merged)]['text'] .= $run['text'];
            } elseif (($run['type'] ?? null) !== 'text' || $run['text'] !== '') {
                $merged[] = $run;
            }
        }

        return $merged;
    }

    /**
     * Text, with $…$ formulas taken out as formulas (outside code).
     *
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function words(string $text, array $marks): array
    {
        $parts = preg_split('/(?<!\$)\$(?!\$)([^$\n]+?)(?<!\$)\$(?!\$)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out[] = ['type' => 'inlineMath', 'attrs' => ['latex' => trim($part)]];
            } elseif ($part !== '') {
                $out[] = self::run($part, $marks);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function run(string $text, array $marks): array
    {
        return ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
    }

    /** @return array<string, mixed> */
    private static function text(string $text): array
    {
        return $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [self::run($text, [])]];
    }

    /** A node's words, without marks. */
    private static function plain(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            $text .= match (true) {
                $child instanceof Text, $child instanceof Code => $child->getLiteral(),
                $child instanceof Newline => "\n",
                default => '',
            };
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function orEmpty(array $blocks): array
    {
        return $blocks === [] ? [['type' => 'paragraph']] : $blocks;
    }
}
