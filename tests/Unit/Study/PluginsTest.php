<?php

namespace Tests\Unit\Study;

use App\Engine\Tools\CalculateTool;
use App\Engine\Tools\CreateSnippetTool;
use App\Engine\Tools\PlotGraphTool;
use App\Study\Plugins;
use App\Study\Tutoring;
use Tests\TestCase;

class PluginsTest extends TestCase
{
    public function test_registry_contains_core_subject_plugins(): void
    {
        $all = Plugins::all();
        $this->assertCount(5, $all);

        $ids = Plugins::ids();
        $this->assertContains('math_physics', $ids);
        $this->assertContains('coding', $ids);
        $this->assertContains('biology_med', $ids);
        $this->assertContains('law', $ids);
        $this->assertContains('languages', $ids);
    }

    public function test_find_returns_plugin_details(): void
    {
        $math = Plugins::find('math_physics');
        $this->assertNotNull($math);
        $this->assertSame('Math & Physics', $math->name);
        $this->assertSame('calculator', $math->icon);
        $this->assertNotEmpty($math->promptInstructions);
        $this->assertNotEmpty($math->starterQuestions);
        $this->assertContains(CalculateTool::class, $math->toolClasses);
        $this->assertContains(PlotGraphTool::class, $math->toolClasses);
        $this->assertContains(CreateSnippetTool::class, $math->toolClasses);

        $this->assertNull(Plugins::find('non_existent'));
    }

    public function test_valid_ids_filters_unknown_and_invalid(): void
    {
        $filtered = Plugins::validIds(['math_physics', 'invalid_plugin', 'coding', null, '']);
        $this->assertSame(['math_physics', 'coding'], $filtered);
    }

    public function test_active_returns_only_valid_plugin_details(): void
    {
        $active = Plugins::active(['math_physics', 'fake', 'law']);
        $this->assertCount(2, $active);
        $this->assertSame('Math & Physics', $active[0]->name);
        $this->assertSame('Law & Legal', $active[1]->name);
    }

    public function test_prompt_has_zero_overhead_when_no_plugins_active(): void
    {
        $this->assertNull(Plugins::prompt([]));
        $this->assertNull(Plugins::prompt(null));
        $this->assertSame([], Plugins::starterQuestions([]));

        $prompt = Tutoring::prompt([
            'method' => 'explain',
            'check_ins' => 'section',
            'quiz' => 'normal',
            'pace' => 'slide',
        ]);

        $this->assertStringNotContainsString('Subject study plugins', $prompt);
        $this->assertStringNotContainsString('KaTeX', $prompt);
    }

    public function test_math_physics_plugin_injects_katex_and_derivation_rules(): void
    {
        $prompt = Plugins::prompt(['math_physics']);
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('Math & Physics plugin active', $prompt);
        $this->assertStringContainsString('KaTeX syntax', $prompt);
        $this->assertStringContainsString('one step at a time', $prompt);
        $this->assertStringContainsString('calculate', $prompt);

        $questions = Plugins::starterQuestions(['math_physics']);
        $this->assertContains('Work this through one step at a time', $questions);

        $fullTutorPrompt = Tutoring::prompt([
            'method' => 'explain',
            'check_ins' => 'section',
            'quiz' => 'normal',
            'pace' => 'slide',
            'plugins' => ['math_physics'],
        ]);

        $this->assertStringContainsString('## Subject study plugins', $fullTutorPrompt);
        $this->assertStringContainsString('Math & Physics plugin active', $fullTutorPrompt);
    }

    public function test_tutoring_summary_includes_active_plugins(): void
    {
        $summary = Tutoring::summary([
            'method' => 'explain',
            'check_ins' => 'section',
            'quiz' => 'normal',
            'pace' => 'slide',
            'plugins' => ['math_physics', 'coding'],
        ]);

        $this->assertStringContainsString('plugins: Math & Physics, Coding & Tech', $summary);
    }

    public function test_tutoring_validated_and_normalised_handles_plugins(): void
    {
        $validated = Tutoring::validated([
            'method' => 'explain',
            'check_ins' => 'section',
            'quiz' => 'normal',
            'pace' => 'slide',
            'plugins' => ['math_physics', 'invalid_xyz', 'law'],
        ]);

        $this->assertSame(['math_physics', 'law'], $validated['plugins']);

        $normalised = Tutoring::normalised($validated);
        $this->assertSame(['math_physics', 'law'], $normalised['plugins']);
    }
}
