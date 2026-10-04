<?php

namespace App\Study;

/**
 * What the AI knows about a course (docs/specs/vistud-2-blueprint.md §3.5.2): the student's own words or what the
 * reader found in a syllabus. A course without one has an empty profile, not none.
 */
final readonly class CourseProfileDetails
{
    /**
     * @param  list<string>  $outcomes
     * @param  list<array{name: string, kind: string, weight: ?int, due_on: ?string, activity_id: ?string}>  $assessment
     * @param  list<array{title: string, starts_on: ?string, ends_on: ?string}>  $proposedModules  found by the reader, not yet added
     */
    public function __construct(
        public string $workspaceId,
        public string $about = '',
        public array $outcomes = [],
        public array $assessment = [],
        public string $textbook = '',
        public ?string $syllabusFileId = null,
        public bool $hasSyllabusText = false,
        public array $proposedModules = [],
        public string $source = 'manual',
        public ?string $model = null,
        public ?string $builtAt = null,
        public ?string $updatedAt = null,
    ) {}

    /** Whether anything is written in it, by the student or the reader. */
    public function hasContent(): bool
    {
        return $this->about !== '' || $this->outcomes !== [] || $this->assessment !== [] || $this->textbook !== '';
    }

    /** Whether there is a syllabus waiting to be read. */
    public function hasSyllabus(): bool
    {
        return $this->syllabusFileId !== null || $this->hasSyllabusText;
    }
}
