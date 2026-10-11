<?php

namespace Tests\Unit\Engine;

use App\Engine\Tools\Context;
use App\Engine\Tools\CreateSnippetTool;
use App\Platform\Access\Principal;
use Tests\TestCase;

class CreateSnippetToolTest extends TestCase
{
    private CreateSnippetTool $tool;

    private Context $context;

    private Principal $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateSnippetTool;
        $this->context = new Context('w1', 'm1', 's1', 'UTC');
        $this->principal = Principal::system();
    }

    public function test_tool_metadata(): void
    {
        $this->assertSame('create_snippet', $this->tool->name());
        $this->assertStringContainsString('copyable', $this->tool->description());

        $params = $this->tool->parameters();
        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('title', $params['properties']);
        $this->assertArrayHasKey('content', $params['properties']);
        $this->assertArrayHasKey('language', $params['properties']);
        $this->assertArrayHasKey('explanation', $params['properties']);
        $this->assertSame(['title', 'content'], $params['required']);
    }

    public function test_formats_code_snippet_with_markdown_block(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Binary Search Implementation',
            'language' => 'python',
            'content' => "def binary_search(arr, target):\n    low, high = 0, len(arr) - 1\n    return -1",
            'explanation' => 'Runs in O(log n) time on sorted arrays.',
        ]);

        $this->assertStringContainsString('### Binary Search Implementation', $result);
        $this->assertStringContainsString('```python', $result);
        $this->assertStringContainsString('def binary_search(arr, target):', $result);
        $this->assertStringContainsString('```', $result);
        $this->assertStringContainsString('Runs in O(log n) time on sorted arrays.', $result);
        $this->assertStringContainsString('1 click', $result);
    }

    public function test_defaults_language_to_text_and_sanitizes_language(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Quick Notes',
            'content' => 'Formula: a^2 + b^2 = c^2',
        ]);

        $this->assertStringContainsString('### Quick Notes', $result);
        $this->assertStringContainsString('```text', $result);
        $this->assertStringContainsString('Formula: a^2 + b^2 = c^2', $result);

        $sanitizedResult = $this->tool->run($this->principal, $this->context, [
            'title' => 'C++ Algorithm',
            'language' => 'C++ 20!',
            'content' => 'int x = 10;',
        ]);

        $this->assertStringContainsString('```c++20', $sanitizedResult);
    }

    public function test_handles_empty_content_gracefully(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Empty snippet',
            'content' => '   ',
        ]);

        $this->assertStringContainsString('Nothing to copy', $result);
    }

    public function test_defaults_title_if_blank(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => '   ',
            'content' => 'Sample content',
        ]);

        $this->assertStringContainsString('### Copyable Snippet', $result);
        $this->assertStringContainsString('Sample content', $result);
    }
}
