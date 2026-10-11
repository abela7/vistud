<?php

namespace Tests\Unit\Study;

use App\Study\NoteDoc;
use PHPUnit\Framework\TestCase;

class NoteDocTest extends TestCase
{
    public function test_preset_highlights_are_accepted_and_cleaned(): void
    {
        foreach (['yellow', 'green', 'blue', 'pink', 'purple', 'orange'] as $tone) {
            $doc = [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Important note in {$tone}",
                                'marks' => [
                                    ['type' => 'highlight', 'attrs' => ['tone' => $tone]],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $cleaned = NoteDoc::clean($doc);
            $mark = $cleaned['content'][0]['content'][0]['marks'][0];
            $this->assertSame('highlight', $mark['type']);
            $this->assertSame($tone, $mark['attrs']['tone']);
        }
    }

    public function test_custom_color_highlights_are_accepted_and_cleaned(): void
    {
        $customColors = ['#fef08a', '#ff00aa', '#123456', '#aacc00'];
        foreach ($customColors as $hex) {
            $doc = [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Highlighted in {$hex}",
                                'marks' => [
                                    ['type' => 'highlight', 'attrs' => ['color' => $hex]],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $cleaned = NoteDoc::clean($doc);
            $mark = $cleaned['content'][0]['content'][0]['marks'][0];
            $this->assertSame('highlight', $mark['type']);
            $this->assertSame($hex, $mark['attrs']['color']);
        }
    }

    public function test_invalid_highlight_tone_or_color_falls_back_to_default(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Corrupt highlight attributes',
                            'marks' => [
                                ['type' => 'highlight', 'attrs' => ['tone' => 'laser-neon', 'color' => 'rainbow']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $cleaned = NoteDoc::clean($doc);
        $mark = $cleaned['content'][0]['content'][0]['marks'][0];
        $this->assertSame('highlight', $mark['type']);
        $this->assertSame('yellow', $mark['attrs']['tone']);
        $this->assertArrayNotHasKey('color', $mark['attrs']);
    }

    public function test_highlighted_text_renders_to_markdown(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'highlighted text',
                            'marks' => [
                                ['type' => 'highlight', 'attrs' => ['color' => '#fef08a']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $markdown = NoteDoc::markdown($doc);
        $this->assertSame('==highlighted text==', $markdown);
    }

    public function test_preset_text_colors_are_accepted_and_cleaned(): void
    {
        foreach (['red', 'blue', 'green', 'purple', 'orange'] as $tone) {
            $doc = [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Colored in {$tone}",
                                'marks' => [
                                    ['type' => 'textColor', 'attrs' => ['tone' => $tone]],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $cleaned = NoteDoc::clean($doc);
            $mark = $cleaned['content'][0]['content'][0]['marks'][0];
            $this->assertSame('textColor', $mark['type']);
            $this->assertSame($tone, $mark['attrs']['tone']);
        }
    }

    public function test_custom_hex_text_colors_are_accepted_and_cleaned(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Custom text color',
                            'marks' => [
                                ['type' => 'textColor', 'attrs' => ['color' => '#38bdf8']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $cleaned = NoteDoc::clean($doc);
        $mark = $cleaned['content'][0]['content'][0]['marks'][0];
        $this->assertSame('textColor', $mark['type']);
        $this->assertSame('#38bdf8', $mark['attrs']['color']);
    }
}
