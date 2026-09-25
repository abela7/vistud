<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Platform\Access\LearnerScope;
use Illuminate\Support\Facades\DB;

/**
 * Small builders for entry specifications (docs/architecture/contracts.md),
 * for developer tests. The golden replay fixtures (work package V1) are
 * written separately, from the spec.
 */
trait BuildsJournalEntries
{
    protected function learnerScopeOf(User $user): LearnerScope
    {
        return LearnerScope::forJob((string) DB::table('learners')->where('user_id', $user->id)->value('id'));
    }

    protected function learnerActor(LearnerScope $scope): array
    {
        return ['type' => 'learner', 'id' => $scope->learnerId, 'channel' => 'web'];
    }

    protected function rule(string $id = 'resolver'): array
    {
        return ['type' => 'processor', 'id' => $id, 'channel' => 'job'];
    }

    protected function definesTopic(string $id, string $topic, string $at = '2026-10-12T20:00:00+01:00'): array
    {
        return [
            'id' => $id, 'kind' => 'claim', 'actor' => $this->rule(), 'occurred_at' => $at,
            'body' => [
                'type' => 'defines', 'targets' => ["topic:{$topic}"], 'value' => ['entity_type' => 'topic', 'status' => 'active', 'kind' => 'concept', 'aliases' => []],
                'confidence' => null, 'method' => ['kind' => 'rule', 'id' => 'setup', 'version' => '1'], 'review' => ['state' => 'accepted'],
            ],
        ];
    }

    protected function taskRecord(string $id, string $task, string $key, string $at = '2026-10-12T20:00:00+01:00'): array
    {
        return [
            'id' => $id, 'kind' => 'record', 'actor' => $this->rule(), 'occurred_at' => $at,
            'body' => ['record_type' => 'task', 'record_id' => $task, 'key' => $key, 'revision' => 1, 'status' => 'active'],
            'content' => ['prompt' => "Prompt for {$key}"],
        ];
    }

    protected function exercises(string $id, string $task, string $topic, string $at = '2026-10-12T20:00:00+01:00'): array
    {
        return [
            'id' => $id, 'kind' => 'claim', 'actor' => $this->rule(), 'occurred_at' => $at,
            'body' => [
                'type' => 'relates', 'targets' => ["task:{$task}", "topic:{$topic}"], 'value' => ['relation' => 'exercises', 'status' => 'active'],
                'confidence' => null, 'method' => ['kind' => 'rule', 'id' => 'setup', 'version' => '1'], 'review' => ['state' => 'accepted'],
            ],
        ];
    }

    protected function attempt(LearnerScope $scope, string $id, string $task, string $outcome, string $at, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'kind' => 'attempt', 'actor' => $this->learnerActor($scope), 'origin' => 'first_hand',
            'occurred_at' => $at, 'session' => 'S-'.substr($at, 0, 10),
            'body' => [
                'task' => $task, 'task_revision' => 1, 'form' => 'apply', 'support' => 'unaided', 'setting' => 'lab',
                'outcome' => $outcome, 'judged_by' => 'auto',
                'checker' => ['id' => 'lab-tests', 'version' => 1, 'key_source' => 'course_material'],
            ],
            'content' => ['answer' => 'SELECT * FROM customers LEFT JOIN orders USING (customer_id);'],
        ], $overrides);
    }

    protected function interpreterJudges(string $id, string $attemptId, array $value, string $at): array
    {
        return [
            'id' => $id, 'kind' => 'claim', 'actor' => $this->rule('interpreter'), 'occurred_at' => $at,
            'body' => [
                'type' => 'judges', 'targets' => ["event:{$attemptId}"], 'value' => $value, 'confidence' => 0.85,
                'method' => ['kind' => 'interpreter', 'id' => 'local-interpreter', 'version' => '1', 'prompt' => 'judge-attempt@1'],
                'review' => ['state' => 'accepted', 'by' => 'policy@1'],
            ],
        ];
    }

    protected function learnerReview(LearnerScope $scope, string $id, string $target, string $decision, string $at, array $value = []): array
    {
        return [
            'id' => $id, 'kind' => 'claim', 'actor' => $this->learnerActor($scope), 'occurred_at' => $at,
            'body' => [
                'type' => 'reviews', 'targets' => [$target], 'value' => ['decision' => $decision, 'reason' => 'wrong_outcome'] + $value,
                'confidence' => null, 'method' => ['kind' => 'person', 'id' => $scope->learnerId, 'version' => '1'], 'review' => ['state' => 'accepted'],
            ],
        ];
    }

    /** Topic T-LEFT, task TK-1 exercising it: three entries every scenario starts from. */
    protected function setupEntries(): array
    {
        return [
            $this->definesTopic('K1', 'T-LEFT'),
            $this->taskRecord('R1', 'TK-1', 'LAB/q1'),
            $this->exercises('K2', 'TK-1', 'T-LEFT'),
        ];
    }
}
