<?php

namespace App\Study;

/**
 * Where the student stands in a course, topic to module to course (docs/specs/vistud-2-blueprint.md §3.8). The course's
 * number is the sum of its modules', so a two-topic module weighs what its two topics do, not what a ten-topic one
 * does. Built by Rollups, once per page; the Progress tree, the course home and the Modules page all read it, so their
 * numbers agree.
 */
final readonly class CourseRoll
{
    /**
     * @param  list<ModuleRoll>  $modules  in order
     * @param  list<TopicRoll>  $attention  the topics that need another look, most urgent first
     * @param  array{total: int, due: int, new: int, overdue: int}  $cards
     */
    public function __construct(
        public string $workspaceId,
        public string $today,
        public array $modules,
        /** The topics in no module, when there are any. */
        public ?ModuleRoll $unplaced,
        /** The module the student is in: where they last studied, else the one running today, else the first. */
        public ?string $currentId,
        public array $attention,
        public array $cards,
        /** Questions the student marked stuck, anywhere in the course. */
        public int $stuck,
        public ?SessionDetails $last,
    ) {}

    /** @return list<ModuleRoll> the modules, then the group with no module */
    public function groups(): array
    {
        return $this->unplaced === null ? $this->modules : [...$this->modules, $this->unplaced];
    }

    /** @return list<TopicRoll> */
    public function topics(): array
    {
        return array_merge([], ...array_map(fn (ModuleRoll $group) => $group->topics, $this->groups()));
    }

    public function module(?string $id): ?ModuleRoll
    {
        foreach ($this->modules as $module) {
            if ($module->id() === $id) {
                return $module;
            }
        }

        return null;
    }

    public function total(): int
    {
        return array_sum(array_map(fn (ModuleRoll $group) => $group->total(), $this->groups()));
    }

    public function done(): int
    {
        return array_sum(array_map(fn (ModuleRoll $group) => $group->done(), $this->groups()));
    }

    public function percent(): int
    {
        return $this->total() === 0 ? 0 : (int) round($this->done() / $this->total() * 100);
    }

    public function mastered(): int
    {
        return count(array_filter($this->topics(), fn (TopicRoll $topic) => $topic->shown === 'mastered'));
    }

    public function notStarted(): int
    {
        return count(array_filter($this->topics(), fn (TopicRoll $topic) => ! $topic->started()));
    }
}
