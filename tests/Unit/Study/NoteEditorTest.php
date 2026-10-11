<?php

namespace Tests\Unit\Study;

use App\Study\NoteDoc;
use App\Study\NoteEditor;
use PHPUnit\Framework\TestCase;

class NoteEditorTest extends TestCase
{
    public function test_replace_text_in_code_block(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Enter the following command to view manual:'],
                    ],
                ],
                [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 'bash'],
                    'content' => [
                        ['type' => 'text', 'text' => 'man ls'],
                    ],
                ],
            ],
        ];

        $result = NoteEditor::replaceText($doc, 'man ls', 'man ls (Example)');
        $this->assertSame(1, $result['count']);
        $codeBlock = $result['doc']['content'][1];
        $this->assertSame('codeBlock', $codeBlock['type']);
        $this->assertSame('man ls (Example)', $codeBlock['content'][0]['text']);
        $this->assertSame('Enter the following command to view manual:', $result['doc']['content'][0]['content'][0]['text']);
    }

    public function test_replace_text_case_insensitive_fallback(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Query the manual using the MAN command.'],
                    ],
                ],
            ],
        ];

        $result = NoteEditor::replaceText($doc, 'man command', 'man command (CLI manual)');
        $this->assertSame(1, $result['count']);
        $this->assertSame('Query the manual using the man command (CLI manual).', $result['doc']['content'][0]['content'][0]['text']);
    }

    public function test_replace_text_in_markdown_multi_line(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [
                        ['type' => 'text', 'text' => 'Section One'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Introductory details here.'],
                    ],
                ],
            ],
        ];

        $result = NoteEditor::replaceText($doc, "## Section One\n\nIntroductory details here.", "## Section 1: Overview\n\nRevised introduction.");
        $this->assertSame(1, $result['count']);
        $md = NoteDoc::markdown($result['doc']);
        $this->assertStringContainsString('Section 1: Overview', $md);
        $this->assertStringContainsString('Revised introduction.', $md);
    }

    public function test_insert_after_and_before(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Step 1: Check options.']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Step 2: Run script.']],
                ],
            ],
        ];

        $after = NoteEditor::insertAfter($doc, 'Step 1', 'Note: Make sure permissions are set.');
        $this->assertSame(1, $after['count']);
        $this->assertCount(3, $after['doc']['content']);

        $before = NoteEditor::insertBefore($doc, 'Step 1', '# Pre-requisites');
        $this->assertSame(1, $before['count']);
        $this->assertSame('heading', $before['doc']['content'][0]['type']);
    }

    public function test_prepend_and_append(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Middle content.']],
                ],
            ],
        ];

        $prepended = NoteEditor::prepend($doc, '# Title');
        $this->assertSame(1, $prepended['count']);
        $this->assertSame('heading', $prepended['doc']['content'][0]['type']);

        $appended = NoteEditor::append($doc, 'Final summary paragraph.');
        $this->assertSame(1, $appended['count']);
        $this->assertCount(2, $appended['doc']['content']);
    }

    public function test_replace_all(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Old content to discard.']],
                ],
            ],
        ];

        $replaced = NoteEditor::replaceAll($doc, "# Brand New Note\n\nFresh contents.");
        $this->assertSame(1, $replaced['count']);
        $this->assertSame('heading', $replaced['doc']['content'][0]['type']);
        $this->assertStringContainsString('Brand New Note', NoteDoc::markdown($replaced['doc']));
    }
}
