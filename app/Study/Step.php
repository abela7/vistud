<?php

namespace App\Study;

/**
 * The one thing to do next (App\Study\NextStep): a line, the button's label, where it goes. A step that studies
 * starts a session instead of going to a page (`study`, with the module and the topic to start on).
 */
final readonly class Step
{
    public function __construct(
        /** Which row of the rule decided it: continue, due_soon, add_module, add_files, add_topics, study_new, study_topic, review, attention, next_module, deadline, caught_up. */
        public string $kind,
        public string $text,
        public string $label,
        public string $url,
        public bool $study = false,
        public ?string $moduleId = null,
        public ?string $topicId = null,
    ) {}
}
