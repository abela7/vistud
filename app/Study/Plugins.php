<?php

namespace App\Study;

use App\Engine\Tools\CalculateTool;
use App\Engine\Tools\CreateSnippetTool;
use App\Engine\Tools\PlotGraphTool;

/**
 * Registry of study subject plugins (Math, Coding, Biology, Law, Languages).
 * Plugins add domain-specific reasoning guidelines, formatting rules,
 * and tools to study sessions without bloating prompt tokens when inactive.
 */
final class Plugins
{
    /** @var array<string, array{name: string, icon: string, category: string, badge: string, description: string, promptInstructions: list<string>, starterQuestions: list<string>, toolClasses?: list<string>}> */
    private const DEFINITIONS = [
        'math_physics' => [
            'name' => 'Math & Physics',
            'icon' => 'calculator',
            'category' => 'STEM',
            'badge' => 'KaTeX, calculation and graphs',
            'description' => 'A general mathematics way of working: KaTeX, one step at a time, numbers from the calculator, and a graph when the shape matters. It covers school algebra through analysis, and applied problems that have units.',
            'promptInstructions' => [
                'Write every mathematical expression in KaTeX syntax: inline with $...$ and on its own line with $$...$$.',
                'Work one step at a time. State what is given and what is asked, name the method, then show each algebraic or logical step. A proof states the claim and then the steps. An applied quantity keeps its unit. Do not hide the working in a paragraph.',
                'Any number the answer depends on comes from the calculate tool (a value, a derivative at a point, a definite integral, or a root). Quote the value it returns. If it rejects the expression, say so and correct it. Do not guess a number, and do not treat the tool as a proof.',
                'When the shape of a function, a sequence, or a data set matters, call plot_graph and embed the mermaid block it returns. Pass parameters in variables. Say whether angles are radians or degrees. If it cannot plot, narrow the range instead of drawing a curve by hand.',
            ],
            'starterQuestions' => [
                'Work this through one step at a time',
                'Check this value with a calculation',
                'Plot this function',
                'Differentiate or integrate this',
            ],
            'toolClasses' => [
                CalculateTool::class,
                PlotGraphTool::class,
                CreateSnippetTool::class,
            ],
        ],
        'coding' => [
            'name' => 'Coding & Tech',
            'icon' => 'code',
            'category' => 'Computer Science',
            'badge' => 'Clean Code & Big-O',
            'description' => 'Provides syntax-highlighted code blocks, algorithmic complexity analysis (Big-O), and boundary test cases.',
            'promptInstructions' => [
                'Always format code inside fenced code blocks with specific language tags (e.g. ```python, ```javascript).',
                'When providing code implementations, scripts, configurations, or algorithmic templates for the student to practice with or run, call the "create_snippet" tool or fenced code blocks so the student gets an instant 1-click copy box.',
                'Whenever discussing algorithms or data structures, explicitly state both Time Complexity ($O(n)$) and Space Complexity.',
                'Include 1 happy-path example input/output and at least 1 edge-case or boundary condition.',
                'Prioritize clean, readable, idiomatic code with concise inline comments over cryptic one-liners.',
            ],
            'starterQuestions' => [
                'Analyze time and space complexity',
                'Generate a copyable code template',
                'Provide edge-case test cases',
                'Refactor this into idiomatic code',
            ],
            'toolClasses' => [
                CreateSnippetTool::class,
            ],
        ],
        'biology_med' => [
            'name' => 'Biology & Med',
            'icon' => 'microscope',
            'category' => 'Life Sciences',
            'badge' => 'Pathways & Systems',
            'description' => 'Structures biological mechanisms into chronological pathways and connects molecular processes to physiology.',
            'promptInstructions' => [
                'Structure biological and biochemical processes into chronological stages: Trigger / Stimulus -> Molecular Mechanism -> Physiological Outcome.',
                'Clearly contrast normal baseline physiology against diseased / pathological states.',
                'Relate microscopic cellular mechanisms to macroscopic anatomical organ systems.',
            ],
            'starterQuestions' => [
                'Explain the biological pathway',
                'What goes wrong in disease states?',
                'Compare and contrast these mechanisms',
            ],
        ],
        'law' => [
            'name' => 'Law & Legal',
            'icon' => 'scale',
            'category' => 'Humanities & Law',
            'badge' => 'IRAC Method',
            'description' => 'Analyzes legal problems and case briefs using the standard IRAC method (Issue, Rule, Application, Conclusion).',
            'promptInstructions' => [
                'Structure legal analyses using the formal IRAC framework: Issue (precise legal question), Rule (controlling statute or precedent), Application (applying rule to facts), Conclusion (concise determination).',
                'Clearly distinguish between binding majority holding, dissenting viewpoints, and policy rationales.',
            ],
            'starterQuestions' => [
                'Analyze this scenario with IRAC',
                'Identify counterarguments and defenses',
                'Explain the controlling legal precedent',
            ],
        ],
        'languages' => [
            'name' => 'Languages',
            'icon' => 'languages',
            'category' => 'Languages',
            'badge' => 'Bilingual Breakdown',
            'description' => 'Presents target-language examples with inline vocabulary tables, phonetic guides, and literal vs. natural translations.',
            'promptInstructions' => [
                'When introducing target-language phrases, provide a 3-part breakdown: target text, literal word-for-word translation, and natural colloquial translation.',
                'Highlight false cognates, grammatical gender/conjugations, and cultural usage context.',
                'Encourage realistic interactive dialogue with gentle, encouraging corrections.',
            ],
            'starterQuestions' => [
                'Explain grammar with examples',
                'Give literal vs natural translation',
                'Practice a realistic dialogue',
            ],
        ],
    ];

    /** @return list<PluginDetails> */
    public static function all(): array
    {
        $plugins = [];
        foreach (self::DEFINITIONS as $id => $def) {
            $plugins[] = new PluginDetails(
                id: $id,
                name: $def['name'],
                icon: $def['icon'],
                category: $def['category'],
                badge: $def['badge'],
                description: $def['description'],
                promptInstructions: $def['promptInstructions'],
                starterQuestions: $def['starterQuestions'],
                toolClasses: $def['toolClasses'] ?? [],
            );
        }

        return $plugins;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function find(string $id): ?PluginDetails
    {
        if (! isset(self::DEFINITIONS[$id])) {
            return null;
        }

        $def = self::DEFINITIONS[$id];

        return new PluginDetails(
            id: $id,
            name: $def['name'],
            icon: $def['icon'],
            category: $def['category'],
            badge: $def['badge'],
            description: $def['description'],
            promptInstructions: $def['promptInstructions'],
            starterQuestions: $def['starterQuestions'],
            toolClasses: $def['toolClasses'] ?? [],
        );
    }

    /**
     * @param  array<array-key, mixed>|string|null  $stored
     * @return list<PluginDetails>
     */
    public static function active(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $active = [];
        foreach ($stored as $id) {
            if (is_string($id) && ($plugin = self::find($id)) !== null) {
                $active[] = $plugin;
            }
        }

        return $active;
    }

    /**
     * @param  array<array-key, mixed>|null  $activeIds
     * @return list<string>
     */
    public static function validIds(?array $activeIds): array
    {
        if ($activeIds === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($id) => is_string($id) ? trim($id) : '', $activeIds),
            fn (string $id) => isset(self::DEFINITIONS[$id])
        ));
    }

    /**
     * Renders prompt instructions for all active plugins. Returns null if none active.
     *
     * @param  array<array-key, mixed>|null  $activeIds
     */
    public static function prompt(?array $activeIds): ?string
    {
        $plugins = self::active($activeIds);
        if ($plugins === []) {
            return null;
        }

        $blocks = [];
        foreach ($plugins as $plugin) {
            $lines = ["### {$plugin->name} plugin active ({$plugin->badge})"];
            foreach ($plugin->promptInstructions as $rule) {
                $lines[] = "- {$rule}";
            }
            $blocks[] = implode("\n", $lines);
        }

        return "## Subject study plugins\n\n".implode("\n\n", $blocks);
    }

    /**
     * Starter suggestions from active plugins.
     *
     * @param  array<array-key, mixed>|null  $activeIds
     * @return list<string>
     */
    public static function starterQuestions(?array $activeIds): array
    {
        $questions = [];
        foreach (self::active($activeIds) as $plugin) {
            foreach ($plugin->starterQuestions as $q) {
                if (! in_array($q, $questions, true)) {
                    $questions[] = $q;
                }
            }
        }

        return $questions;
    }
}
