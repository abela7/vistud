<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Carbon\CarbonImmutable;

/**
 * How progress adds up (docs/specs/vistud-2-blueprint.md §3.8): topic, then module, then course. A topic is understood
 * when the student says so or the evidence has earned it mastery; a module is those out of its topics; the course is
 * the sum over its modules, so a module weighs what its topics do. Topics in no module make a group of their own.
 *
 * What needs attention (and so feeds the Next line and the Progress filter): a topic still confusing after a week, a
 * question about it stuck, a test of it under 50 % (unless the student has said something about it since), cards of it
 * a week or more overdue.
 *
 * One pass over the course per call: a handful of queries however many topics it has. Nothing is kept between requests,
 * so what a page shows is never behind what was just saved; a page calls it once and passes the result around.
 */
final class Rollups
{
    /** Days a topic may stay confusing before it needs attention. */
    public const CONFUSING_DAYS = 7;

    /** A test under this score needs attention. */
    public const TEST_BELOW = 50;

    public function __construct(
        private Memory $memory,
        private Modules $modules,
        private Topics $topics,
        private Files $files,
        private Notes $notes,
        private Sessions $sessions,
        private Flashcards $flashcards,
        private Questions $questions,
        private Quizzes $quizzes,
        private TopicSuggestions $suggestions,
    ) {}

    public function for(Principal $by, string $workspaceId): CourseRoll
    {
        $scope = Guard::learner($by);
        $modules = $this->modules->list($by, $workspaceId);
        // The journal is read once for the topics and the questions both.
        $snapshot = $this->memory->snapshot($scope);
        $topics = $this->topics->list($by, $workspaceId, $snapshot);
        $zone = $this->sessions->timezone($by);
        $today = CarbonImmutable::now($zone)->toDateString();
        $last = $this->sessions->list($by, $workspaceId, 1)[0] ?? null;
        $cards = $this->flashcards->counts($by, $workspaceId);
        $questions = $this->questions->list($by, $workspaceId, snapshot: $snapshot);
        $quizzes = $this->quizzes->forWorkspace($by, $workspaceId);
        $waiting = $this->suggestions->waiting($by, $workspaceId);

        $sessions = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $workspaceId)->whereNotNull('topic_id')->selectRaw('topic_id, count(*) as n')->groupBy('topic_id')->get() as $row) {
            $sessions[(string) $row->topic_id] = (int) $row->n;
        }
        $open = [];
        $stuck = [];
        foreach ($questions as $question) {
            if ($question->status !== 'answered' && $question->topicId !== null) {
                $open[$question->topicId] = ($open[$question->topicId] ?? 0) + 1;
            }
            if ($question->status === 'stuck') {
                $stuck[$question->topicId ?? ''] = ($stuck[$question->topicId ?? ''] ?? 0) + 1;
            }
        }
        $materials = [];
        foreach ([...$this->files->list($by, $workspaceId), ...$this->notes->list($by, $workspaceId)] as $item) {
            $materials[$item->moduleId ?? ''] = ($materials[$item->moduleId ?? ''] ?? 0) + 1;
        }

        // The latest test of each topic, and the latest quiz or test of each module: newest first, so the first one seen wins.
        $tests = [];
        $tested = [];
        foreach ($quizzes as $quiz) {
            if ($quiz->moduleId !== null) {
                $tested[$quiz->moduleId] ??= $quiz->score;
            }
            foreach ($quiz->proposals as $proposal) {
                $tests[$proposal['topic_id']] ??= ['score' => $proposal['score'], 'at' => $quiz->finishedAt];
            }
        }

        $rolls = [];
        foreach ($topics as $topic) {
            $roll = $this->roll($topic, $zone, $today, $cards['topics'][$topic->id] ?? [], $open[$topic->id] ?? 0, $stuck[$topic->id] ?? 0, $sessions[$topic->id] ?? 0, $tests[$topic->id] ?? null);
            $rolls[$topic->moduleId ?? ''][] = $roll;
        }

        $groups = [];
        foreach ($modules as $index => $module) {
            $groups[] = new ModuleRoll(
                $module, $index + 1, $rolls[$module->id] ?? [], $materials[$module->id] ?? 0, $tested[$module->id] ?? null,
                $waiting[$module->id]['count'] ?? 0, $waiting[$module->id]['from'] ?? null,
            );
        }
        // Topics in no module, or in one that is gone, make the group of their own.
        $known = array_flip(array_map(fn (ModuleDetails $module) => $module->id, $modules));
        $loose = [];
        foreach ($rolls as $moduleId => $list) {
            if ($moduleId === '' || ! isset($known[$moduleId])) {
                $loose = [...$loose, ...$list];
            }
        }
        usort($loose, fn (TopicRoll $a, TopicRoll $b) => $a->topic->position <=> $b->topic->position);
        $unplaced = $loose === [] ? null : new ModuleRoll(null, 0, $loose, $materials[''] ?? 0);

        return new CourseRoll(
            workspaceId: $workspaceId,
            today: $today,
            modules: $groups,
            unplaced: $unplaced,
            currentId: self::current($modules, $last, $today),
            attention: self::attention([...$groups, ...($unplaced === null ? [] : [$unplaced])]),
            cards: ['total' => $cards['total'], 'due' => $cards['due'], 'new' => $cards['new'], 'overdue' => $cards['overdue']],
            stuck: array_sum($stuck),
            last: $last,
        );
    }

    /**
     * The module the student is in: where they last studied, else the one running today, else the first.
     *
     * @param  list<ModuleDetails>  $modules
     */
    public static function current(array $modules, ?SessionDetails $last, string $today): ?string
    {
        foreach ($modules as $module) {
            if ($last?->moduleId === $module->id) {
                return $module->id;
            }
        }
        foreach ($modules as $module) {
            if ($module->startsOn !== null && $module->startsOn <= $today && ($module->endsOn === null || $module->endsOn >= $today)) {
                return $module->id;
            }
        }

        return $modules[0]->id ?? null;
    }

    /**
     * @param  array{total?: int, due?: int, overdue?: int}  $cards
     * @param  ?array{score: int, at: string}  $test
     */
    private function roll(TopicDetails $topic, string $zone, string $today, array $cards, int $open, int $stuck, int $sessions, ?array $test): TopicRoll
    {
        $shown = $topic->shown();
        $since = $topic->statusAt === null ? null : CarbonImmutable::parse($topic->statusAt, 'UTC')->setTimezone($zone)->toDateString();
        $overdue = $cards['overdue'] ?? 0;

        $reasons = [];
        if ($shown === 'confused' && ($since === null || $since <= CarbonImmutable::parse($today)->subDays(self::CONFUSING_DAYS)->toDateString())) {
            $reasons[] = 'confusing';
        }
        if ($stuck > 0) {
            $reasons[] = 'stuck';
        }
        // The student's word after the test is the newer evidence, and clears it.
        $said = $topic->statusBy === 'student' && $topic->statusAt !== null && $test !== null && CarbonImmutable::parse($topic->statusAt, 'UTC')->gt(CarbonImmutable::parse($test['at']));
        if ($test !== null && $test['score'] < self::TEST_BELOW && ! $said) {
            $reasons[] = 'test';
        }
        if ($overdue > 0) {
            $reasons[] = 'overdue';
        }

        return new TopicRoll(
            topic: $topic, shown: $shown, cards: $cards['total'] ?? 0, cardsDue: $cards['due'] ?? 0, cardsOverdue: $overdue,
            openQuestions: $open, stuckQuestions: $stuck, sessions: $sessions, tested: $test['score'] ?? null, reasons: $reasons, since: $since,
        );
    }

    /**
     * The topics that need attention, most urgent first: by their most urgent reason (confusing, stuck, test, overdue),
     * the longest confusing first, then in the course's order.
     *
     * @param  list<ModuleRoll>  $groups
     * @return list<TopicRoll>
     */
    private static function attention(array $groups): array
    {
        $items = [];
        foreach ($groups as $group) {
            foreach ($group->topics as $topic) {
                if ($topic->needsAttention()) {
                    $items[] = [array_search($topic->reasons[0], TopicRoll::REASONS, true), $topic->since ?? '9999-12-31', $group->number === 0 ? PHP_INT_MAX : $group->number, $topic->topic->position, $topic];
                }
            }
        }
        usort($items, fn (array $a, array $b) => array_slice($a, 0, 4) <=> array_slice($b, 0, 4));

        return array_map(fn (array $item) => $item[4], $items);
    }
}
