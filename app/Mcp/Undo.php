<?php

namespace App\Mcp;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\TopicDetails;
use App\Study\Topics;
use Illuminate\Support\Collection;

/**
 * "Undo what this app did" (docs/specs/mcp-connections-plan.md §3.8): for one connection and a time window, takes back
 * what its calls saved, by the ids in `mcp_calls`. Cards, key points and questions it made are deleted as the student
 * would delete them; quizzes it recorded are deleted, which retracts their answers in the journal (an amendment, never
 * an erasure); topic marks it set go back to what they were, unless the student has said something since; answers it
 * wrote to open questions are taken back. Left as they are: topics it added, notes it wrote (every note keeps its
 * versions), card reviews, and what it logged on assignments. A call undone once is not undone twice.
 */
final class Undo
{
    /** @var array<string, string> the windows the screen offers */
    public const WINDOWS = [
        'hour' => 'the last hour',
        'day' => 'the last 24 hours',
        'week' => 'the last 7 days',
        'all' => 'everything it did',
    ];

    public function __construct(
        private Flashcards $cards,
        private Findings $findings,
        private Questions $questions,
        private Quizzes $quizzes,
        private Topics $topics,
    ) {}

    /**
     * What Undo would take back now, by kind.
     *
     * @return array{flashcards: int, key_points: int, questions: int, answers: int, quizzes: int, marks: int}
     */
    public function plan(Principal $by, string $connectionId, string $window): array
    {
        $found = $this->found(Guard::learner($by), $connectionId, $window);

        return [
            'flashcards' => count($found['flashcard']),
            'key_points' => count($found['finding']),
            'questions' => count($found['question']),
            'answers' => count($found['answered']),
            'quizzes' => count($found['quiz']),
            'marks' => count($found['marks']),
        ];
    }

    /**
     * Takes it back. Returns what was taken back (as plan() counts it before, so a screen can say it).
     *
     * @return array{flashcards: int, key_points: int, questions: int, answers: int, quizzes: int, marks: int}
     */
    public function run(Principal $by, string $connectionId, string $window): array
    {
        $scope = Guard::learner($by);
        $found = $this->found($scope, $connectionId, $window);
        $done = ['flashcards' => 0, 'key_points' => 0, 'questions' => 0, 'answers' => 0, 'quizzes' => 0, 'marks' => 0];

        // Newest mark first: each goes back to its own "before", so a topic marked twice returns to where it began.
        foreach ($found['marks'] as $mark) {
            try {
                $this->topics->unmark($by, $mark['topic_id'], $mark['before']);
                $done['marks']++;
            } catch (NotFound) {
                // The topic is gone.
            }
        }
        $each = [
            'flashcard' => ['flashcards', fn (string $id) => $this->cards->delete($by, $id)],
            'finding' => ['key_points', fn (string $id) => $this->findings->delete($by, $id)],
            'question' => ['questions', fn (string $id) => $this->questions->delete($by, $id)],
            'quiz' => ['quizzes', fn (string $id) => $this->quizzes->delete($by, $id)],
            'answered' => ['answers', fn (string $id) => $this->questions->reopenAiAnswer($by, $id)],
        ];
        foreach ($each as $kind => [$counted, $take]) {
            foreach ($found[$kind] as $id) {
                try {
                    $take($id);
                    $done[$counted]++;
                } catch (NotFound) {
                    // Already gone.
                }
            }
        }
        $this->markUndone($scope, $found['calls']);

        return $done;
    }

    /**
     * Counts said in words: "2 flashcards, 1 quiz"; null when there is nothing.
     *
     * @param  array<string, int>  $counts
     */
    public static function words(array $counts): ?string
    {
        $names = ['flashcards' => ['flashcard', 'flashcards'], 'key_points' => ['key point', 'key points'], 'questions' => ['question', 'questions'], 'answers' => ['answer to a question', 'answers to questions'], 'quizzes' => ['quiz or test', 'quizzes and tests'], 'marks' => ['topic mark', 'topic marks']];
        $parts = [];
        foreach ($names as $key => [$one, $many]) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = $counts[$key].' '.($counts[$key] === 1 ? $one : $many);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The ids in the window's calls that are still there to take back.
     *
     * @return array{flashcard: list<string>, finding: list<string>, question: list<string>, quiz: list<string>, answered: list<string>, marks: list<array{topic_id: string, before: array<string, ?string>}>, calls: list<string>}
     */
    private function found(LearnerScope $scope, string $connectionId, string $window): array
    {
        $found = ['flashcard' => [], 'finding' => [], 'question' => [], 'quiz' => [], 'answered' => [], 'marks' => [], 'calls' => []];
        $query = LearnerTables::query($scope, 'mcp_calls')->where('connection_id', $connectionId)->where('outcome', Calls::OK)->whereNotNull('effects')
            ->orderByDesc('created_at')->orderByDesc('id');
        if (($since = self::since($window)) !== null) {
            $query->where('created_at', '>=', $since);
        }
        foreach ($query->get(['id', 'effects']) as $call) {
            $effects = json_decode((string) $call->effects, true) ?: [];
            if (isset($effects['undone_at'])) {
                continue;
            }
            $found['calls'][] = (string) $call->id;
            foreach ((array) ($effects['saved_ids'] ?? []) as $kind => $ids) {
                if (isset($found[$kind]) && $kind !== 'marks' && $kind !== 'calls') {
                    array_push($found[$kind], ...array_map('strval', (array) $ids));
                }
            }
            foreach ((array) ($effects['quizzes'] ?? []) as $quiz) {
                is_array($quiz) && is_string($quiz['id'] ?? null) ? $found['quiz'][] = $quiz['id'] : null;
            }
            foreach ((array) ($effects['statuses'] ?? []) as $status) {
                if (is_array($status) && ($status['applied'] ?? false) === true && is_string($status['topic_id'] ?? null) && is_array($status['before'] ?? null)) {
                    $found['marks'][] = ['topic_id' => $status['topic_id'], 'before' => $status['before']];
                }
            }
        }

        // Only what is still there, and for a mark or an answer, still the app's (the student's own word stands).
        foreach (['flashcard' => 'flashcards', 'finding' => 'findings', 'question' => 'questions', 'quiz' => 'quizzes'] as $kind => $table) {
            $found[$kind] = $this->existing($scope, $table, $found[$kind]);
        }
        $found['answered'] = $found['answered'] === [] ? [] : LearnerTables::query($scope, 'questions')->whereIn('id', array_unique($found['answered']))
            ->where('status', 'answered')->where('answered_by', 'ai')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $aiMarked = $found['marks'] === [] ? [] : LearnerTables::query($scope, 'topics')->whereIn('id', array_unique(array_column($found['marks'], 'topic_id')))
            ->whereIn('status_by', TopicDetails::AI_MARKS)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $found['marks'] = array_values(array_filter($found['marks'], fn (array $m) => in_array($m['topic_id'], $aiMarked, true)));
        // An answered question is also a question the app made? Then deleting it covers it.
        $found['answered'] = array_values(array_diff($found['answered'], $found['question']));

        return $found;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function existing(LearnerScope $scope, string $table, array $ids): array
    {
        return $ids === [] ? [] : LearnerTables::query($scope, $table)->whereIn('id', array_values(array_unique($ids)))->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Remembers which calls were undone, so a second Undo does not take back the same thing (or a newer thing with its id). */
    private function markUndone(LearnerScope $scope, array $callIds): void
    {
        /** @var Collection<int, object> $rows */
        $rows = $callIds === [] ? collect() : LearnerTables::query($scope, 'mcp_calls')->whereIn('id', $callIds)->get(['id', 'effects']);
        foreach ($rows as $row) {
            $effects = json_decode((string) $row->effects, true) ?: [];
            $effects['undone_at'] = now()->toIso8601String();
            LearnerTables::query($scope, 'mcp_calls')->where('id', $row->id)->update(['effects' => json_encode($effects, JSON_THROW_ON_ERROR)]);
        }
    }

    private static function since(string $window): ?\DateTimeInterface
    {
        // elapsed: a window of real time back from now.
        return match ($window) {
            'hour' => now()->subHour(),
            'day' => now()->subHours(24),
            'week' => now()->subDays(7),
            default => null,
        };
    }
}
