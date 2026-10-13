<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;

/**
 * One of the course tools (the tutor's: topics, notes, files, set_topic_status…) for ViStud AI, which works above every
 * course: the same tool with a `course` to name, run in that course as if from its page, with no session, module or
 * folder. What it does goes into ViStud AI's chat (the same Effects), and its agreements are asked there.
 */
final class InCourse implements Tool
{
    public function __construct(private Tool $inner, private Courses $courses) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return '[In one course: name it with `course`.] '.$this->inner->description();
    }

    public function parameters(): array
    {
        $parameters = $this->inner->parameters();
        $properties = (array) ($parameters['properties'] ?? []);
        $parameters['properties'] = ['course' => ['type' => 'string', 'description' => 'The course\'s name, as the courses tool gives it.']] + $properties;
        $parameters['required'] = array_values(array_unique(['course', ...($parameters['required'] ?? [])]));

        return $parameters;
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $course = $this->courses->find($by, $input['course'] ?? null);
        if (is_string($course)) {
            return $course;
        }
        unset($input['course']);
        $inCourse = new Context($course->id, null, null, $context->zone, $context->effects, threadId: $context->threadId, model: $context->model);

        return "In {$course->name}:\n".$this->inner->run($by, $inCourse, $input);
    }
}
