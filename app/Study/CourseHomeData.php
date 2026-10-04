<?php

namespace App\Study;

/** Everything a course's home page shows (App\Study\CourseHome), as plain values. */
final readonly class CourseHomeData
{
    /**
     * @param  list<array{module: ModuleDetails, number: int, topics: int, done: int, current: bool, url: string}>  $modules  in order
     * @param  array{done: int, total: int, percent: int, cardsDue: int, stuck: int}  $progress
     * @param  array{moduleId: ?string, topicId: ?string}  $studyTarget  where Study starts, with no dialog
     */
    public function __construct(
        public WorkspaceDetails $workspace,
        public Step $step,
        public array $modules,
        public array $progress,
        public array $studyTarget,
        public ?SessionDetails $openSession,
        public ?SessionDetails $lastSession,
        /** @var array<string, string> topic id => name, for the Continue line */
        public array $topicNames = [],
    ) {}
}
