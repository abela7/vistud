<?php

namespace App\Study;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * A Markdown file, shown on its page as it was meant to look
 * (App\Http\Controllers\FilePageController): GitHub's flavour, so what an AI
 * writes (headings, lists, tables, task lists, code, quotes) comes out right.
 * Raw HTML in the file is stripped and unsafe links are dropped, so a file
 * can't run anything on the page. Formulas ($…$) are drawn in the browser
 * (resources/js/formulas.js).
 */
final class MarkdownPreview
{
    public static function html(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
