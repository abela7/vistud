<?php

namespace App\Study;

/**
 * A study subject plugin that extends the AI tutor with domain-specific
 * formatting, reasoning frameworks, and tools (e.g. Math, Coding, Law, Biology).
 */
final readonly class PluginDetails
{
    /**
     * @param  list<string>  $promptInstructions  Domain-specific rules the tutor must follow when active
     * @param  list<string>  $starterQuestions  Quick prompts/questions tailored to this domain
     * @param  list<string>  $toolClasses  Optional engine Tool class names provided by this plugin
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $icon,
        public string $category,
        public string $badge,
        public string $description,
        public array $promptInstructions = [],
        public array $starterQuestions = [],
        public array $toolClasses = [],
    ) {}
}
