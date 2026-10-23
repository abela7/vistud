<?php

namespace App\Engine;

use App\Study\MarkdownPreview;

/**
 * The UI skill's blocks as the chat draws them (resources/skills/ui/SKILL.md, the owner's ask, 2026-10-23): a model
 * writes a small tag, and ViStud draws the real thing from its own components, in the theme. The model never writes
 * HTML; what it puts inside a block goes through the same Markdown that strips HTML as the rest of its reply, every
 * attribute is checked and escaped, and a block that isn't exactly right (an unknown attribute, too many choices,
 * one block inside another, more than MOST) shows as plain words, its tags gone. Tags inside code stay code.
 *
 * A choice's button sends exactly the words on it as the student's message, only when they tap it.
 */
final class ChatBlocks
{
    public const KINDS = ['card', 'copy', 'choices', 'steps', 'compare'];

    /** The most blocks drawn in one reply; any more show as plain words. */
    public const MOST = 12;

    private const ATTRIBUTES = ['card' => ['title'], 'copy' => [], 'choices' => [], 'steps' => [], 'compare' => ['left', 'right']];

    private const REQUIRED = ['card' => [], 'copy' => [], 'choices' => [], 'steps' => [], 'compare' => ['left', 'right']];

    private const BODY_CHARS = 2000;

    /**
     * A reply still being written, cut before a block that hasn't closed yet, so the chat never shows a half-written
     * block while the words stream in.
     */
    public static function partial(string $text): string
    {
        $kinds = implode('|', self::KINDS);
        if (preg_match_all('/<('.$kinds.')\b/i', $text, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = array_key_last($m[0]);
            [$open, $at] = [strtolower($m[1][$last][0]), $m[0][$last][1]];
            if (preg_match('/<\/'.$open.'\s*>/i', substr($text, $at)) !== 1) {
                $text = substr($text, 0, $at);
            }
        }

        return (string) preg_replace('/<\/?[a-z]*$/i', '', $text);
    }

    /** The reply as HTML: its Markdown drawn, and each well-formed block drawn as ViStud's own component. */
    public static function html(string $markdown): string
    {
        $kinds = implode('|', self::KINDS);
        $spans = self::codeSpans($markdown);
        $salt = bin2hex(random_bytes(6));
        $blocks = [];
        $out = '';
        $pos = 0;
        preg_match_all('/<('.$kinds.')\b([^>]*)>(.*?)<\/\1\s*>/si', $markdown, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            [$whole, $at] = $match[0];
            if ($at < $pos || self::inCode($spans, $at)) {
                continue;
            }
            $html = count($blocks) < self::MOST ? self::render(strtolower($match[1][0]), $match[2][0], $match[3][0]) : null;
            if ($html === null) {
                continue;
            }
            // Its own paragraph, with a name the model can't guess, so the drawn block takes its place after the Markdown.
            $token = 'vsblk'.$salt.count($blocks);
            $blocks[$token] = $html;
            $out .= substr($markdown, $pos, $at - $pos)."\n\n{$token}\n\n";
            $pos = $at + strlen($whole);
        }
        $out .= substr($markdown, $pos);
        // Whatever block tags are left outside code (not right, nested, past MOST): the words stay, the tags go, so
        // Markdown never takes a tag on its own line as the start of HTML to drop.
        $out = self::outsideCode($out, fn (string $s) => (string) preg_replace('/<\/?('.$kinds.')\b[^>]*>/i', '', $s));

        $html = MarkdownPreview::html($out);
        foreach ($blocks as $token => $block) {
            $html = str_replace("<p>{$token}</p>", $block, $html);
        }

        return (string) preg_replace('/vsblk'.$salt.'\d+/', '', $html);
    }

    /** One block as HTML, or null when it isn't exactly as the skill says. */
    private static function render(string $kind, string $attributes, string $body): ?string
    {
        $kinds = implode('|', self::KINDS);
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > self::BODY_CHARS || preg_match('/<\/?('.$kinds.')\b/i', $body) === 1) {
            return null;
        }
        $given = self::attributes($attributes);
        if ($given === null || array_diff(array_keys($given), self::ATTRIBUTES[$kind]) !== [] || array_diff(self::REQUIRED[$kind], array_keys($given)) !== []) {
            return null;
        }

        return match ($kind) {
            'card' => '<section class="chat-card">'.(isset($given['title']) ? '<h4 class="chat-card-title">'.e($given['title']).'</h4>' : '')
                .'<div class="chat-card-body">'.MarkdownPreview::html($body).'</div></section>',
            'copy' => '<div class="chat-copy"><div class="chat-copy-head"><span class="chat-code-lang">Copy</span>'
                .'<button type="button" class="chat-code-copy-btn" data-chat-copy title="Copy to clipboard"><span>Copy</span></button></div>'
                .'<div class="chat-copy-text">'.e($body).'</div></div>',
            'choices' => self::choices($body),
            'steps' => self::steps($body),
            'compare' => self::compare($given['left'], $given['right'], $body),
        };
    }

    /**
     * The attributes, when every one is name="value" with nothing else, each value short, one line and free of tag
     * characters; null otherwise.
     *
     * @return array<string, string>|null
     */
    private static function attributes(string $attributes): ?array
    {
        if (trim($attributes) === '') {
            return [];
        }
        if (preg_match_all('/([a-z]+)="([^"<>\n]{1,80})"/', $attributes, $m, PREG_SET_ORDER) === 0 || trim((string) preg_replace('/[a-z]+="[^"<>\n]{1,80}"/', '', $attributes)) !== '') {
            return null;
        }
        $given = [];
        foreach ($m as [, $name, $value]) {
            // Entities the model wrote (&quot;) are the characters they mean; still no tag characters.
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (isset($given[$name]) || $value === '' || preg_match('/[<>]/', $value) === 1) {
                return null;
            }
            $given[$name] = $value;
        }

        return $given;
    }

    /** @return list<string> the body's lines, trimmed, without empty ones */
    private static function lines(string $body): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $body)), fn (string $line) => $line !== ''));
    }

    private static function choices(string $body): ?string
    {
        $lines = self::lines($body);
        if (count($lines) < 2 || count($lines) > 6) {
            return null;
        }
        $buttons = '';
        foreach ($lines as $line) {
            if (mb_strlen($line) > 120 || preg_match('/[<>]/', $line) === 1) {
                return null;
            }
            // The words sent are exactly the words shown.
            $buttons .= '<button type="button" class="chat-chip chat-choice" data-chat-choice="'.e($line).'">'.e($line).'</button>';
        }

        return '<div class="chat-choices" role="group" aria-label="Choose an answer">'.$buttons.'</div>';
    }

    private static function steps(string $body): ?string
    {
        $lines = self::lines($body);
        if (count($lines) < 2 || count($lines) > 12) {
            return null;
        }
        $items = '';
        foreach ($lines as $line) {
            if (mb_strlen($line) > 200) {
                return null;
            }
            $items .= '<li class="chat-step"><label class="chat-step-label"><input type="checkbox" class="chat-step-box"><span class="chat-step-text">'.self::inline($line).'</span></label></li>';
        }

        return '<ol class="chat-steps">'.$items.'</ol>';
    }

    private static function compare(string $left, string $right, string $body): ?string
    {
        $lines = self::lines($body);
        if ($lines === [] || count($lines) > 10 || mb_strlen($left) > 40 || mb_strlen($right) > 40) {
            return null;
        }
        $rows = '';
        foreach ($lines as $line) {
            $sides = array_map('trim', explode('|', $line, 2));
            if (count($sides) !== 2 || mb_strlen($sides[0]) > 200 || mb_strlen($sides[1]) > 200) {
                return null;
            }
            $rows .= '<tr><td>'.self::inline($sides[0]).'</td><td>'.self::inline($sides[1]).'</td></tr>';
        }

        return '<table class="chat-compare"><thead><tr><th>'.e($left).'</th><th>'.e($right).'</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /** One line of Markdown as inline HTML (bold, code, links), without a paragraph around it. */
    private static function inline(string $line): string
    {
        $html = trim(MarkdownPreview::html($line));

        return preg_match('/\A<p>(.*)<\/p>\z/s', $html, $m) === 1 && ! str_contains($m[1], '<p>') ? $m[1] : $html;
    }

    /** @return list<array{0: int, 1: int}> where the code is: fenced blocks and inline code, as byte ranges */
    private static function codeSpans(string $text): array
    {
        preg_match_all('/```.*?```|`[^`\n]+`/s', $text, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $span) => [$span[1], $span[1] + strlen($span[0])], $m[0]);
    }

    /** @param list<array{0: int, 1: int}> $spans */
    private static function inCode(array $spans, int $at): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($at >= $start && $at < $end) {
                return true;
            }
        }

        return false;
    }

    /** $change applied to the text outside code only. */
    private static function outsideCode(string $text, callable $change): string
    {
        $out = '';
        $pos = 0;
        foreach (self::codeSpans($text) as [$start, $end]) {
            $out .= $change(substr($text, $pos, $start - $pos)).substr($text, $start, $end - $start);
            $pos = $end;
        }

        return $out.$change(substr($text, $pos));
    }
}
