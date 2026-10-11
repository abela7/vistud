<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;

/**
 * Creates and formats copyable snippets (such as code, mathematical formulas, configs,
 * command-line scripts, cheatsheets, templates, or notes) in a clean, copyable block
 * with a title, syntax tag, and a 1-click copy button for the student.
 */
final class CreateSnippetTool implements Tool
{
    public function name(): string
    {
        return 'create_snippet';
    }

    public function description(): string
    {
        return 'Formats copyable code, formulas, templates, scripts, or notes into a clean block with 1-click copy support.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Descriptive title for the snippet.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The exact code, formula, script, or text to copy.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Syntax tag (e.g. python, sql, bash, latex, text). Defaults to "text".',
                ],
                'explanation' => [
                    'type' => 'string',
                    'description' => 'Optional brief tip or explanation.',
                ],
            ],
            'required' => ['title', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $rawTitle = trim((string) ($input['title'] ?? 'Copyable Snippet'));
        $title = $rawTitle !== '' ? $rawTitle : 'Copyable Snippet';

        $rawContent = trim((string) ($input['content'] ?? ''));
        if ($rawContent === '') {
            return 'Nothing to copy: the snippet content was empty. Provide non-empty content to format.';
        }

        $rawLanguage = trim((string) ($input['language'] ?? 'text'));
        $language = preg_replace('/[^a-zA-Z0-9_#+-]/', '', strtolower($rawLanguage));
        if ($language === '' || $language === null) {
            $language = 'text';
        }

        $explanation = trim((string) ($input['explanation'] ?? ''));

        $lines = [];
        $lines[] = "### {$title}";
        $lines[] = "```{$language}";
        $lines[] = $rawContent;
        $lines[] = '```';
        if ($explanation !== '') {
            $lines[] = $explanation;
        }

        $snippetBlock = implode("\n", $lines);

        return "Copyable snippet created successfully:\n\n{$snippetBlock}\n\n(Embed this exact formatted block in your response to the student so they can copy it with 1 click.)";
    }
}
