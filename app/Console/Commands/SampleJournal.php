<?php

namespace App\Console\Commands;

use App\Brain\Store\JournalReader;
use App\Brain\Writer\JournalWriter;
use App\Identity\AccountFactory;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Errors\AppError;
use App\Platform\Ids;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes a few synthetic study entries into a student's empty journal, so
 * the journal pages can be tried before anything else writes to it (the M1
 * walkthrough). Never in production: real journals hold only what really
 * happened (ADR 0001, ADR 0003 D4).
 */
#[Signature('vistud:journal:sample {email}')]
#[Description("Write sample study entries into a student's empty journal (not in production)")]
class SampleJournal extends Command
{
    public function handle(JournalWriter $writer, JournalReader $reader): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Sample entries are for development only.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', AccountFactory::normaliseEmail((string) $this->argument('email')))->first();
        $learnerId = $user === null ? null : DB::table('learners')->where('user_id', $user->id)->value('id');
        if ($learnerId === null) {
            $this->error('No student account has that email address.');

            return self::FAILURE;
        }

        $scope = LearnerScope::forJob((string) $learnerId);
        if ($reader->entries($scope) !== []) {
            $this->error('That journal already has entries. Sample entries only go into an empty one.');

            return self::FAILURE;
        }

        try {
            $writer->appendBatch($scope, $this->entries((string) $learnerId));
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Added 5 sample entries. Log in as '.$user->email.' and open Journal.');

        return self::SUCCESS;
    }

    /** A topic, a practice task on it, and two attempts at the task: wrong, then right. */
    private function entries(string $learnerId): array
    {
        $day = now()->subDay()->format('Y-m-d');
        $setupAt = "{$day}T09:00:00+00:00";
        $system = ['type' => 'processor', 'id' => 'sample', 'channel' => 'job'];
        $method = ['kind' => 'rule', 'id' => 'sample', 'version' => '1'];
        $learner = ['type' => 'learner', 'id' => $learnerId, 'channel' => 'web'];
        $attempt = fn (string $outcome, string $time, string $answer) => [
            'id' => Ids::new(), 'kind' => 'attempt', 'actor' => $learner, 'origin' => 'first_hand',
            'occurred_at' => "{$day}T{$time}+00:00", 'session' => "S-{$day}",
            'body' => [
                'task' => 'TK-SAMPLE-JOINS', 'task_revision' => 1, 'form' => 'apply', 'support' => 'unaided', 'setting' => 'lab',
                'outcome' => $outcome, 'judged_by' => 'auto',
                'checker' => ['id' => 'lab-tests', 'version' => 1, 'key_source' => 'course_material'],
            ],
            'content' => ['answer' => $answer],
        ];

        return [
            [
                'id' => Ids::new(), 'kind' => 'claim', 'actor' => $system, 'occurred_at' => $setupAt,
                'body' => [
                    'type' => 'defines', 'targets' => ['topic:T-SAMPLE-LEFT-JOIN'],
                    'value' => ['entity_type' => 'topic', 'status' => 'active', 'kind' => 'concept', 'aliases' => []],
                    'confidence' => null, 'method' => $method, 'review' => ['state' => 'accepted'],
                ],
            ],
            [
                'id' => Ids::new(), 'kind' => 'record', 'actor' => $system, 'occurred_at' => $setupAt,
                'body' => ['record_type' => 'task', 'record_id' => 'TK-SAMPLE-JOINS', 'key' => 'SQL-LAB-3/q1', 'revision' => 1, 'status' => 'active'],
                'content' => ['prompt' => 'List every customer with their orders, including customers who have not ordered anything.'],
            ],
            [
                'id' => Ids::new(), 'kind' => 'claim', 'actor' => $system, 'occurred_at' => $setupAt,
                'body' => [
                    'type' => 'relates', 'targets' => ['task:TK-SAMPLE-JOINS', 'topic:T-SAMPLE-LEFT-JOIN'],
                    'value' => ['relation' => 'exercises', 'status' => 'active'],
                    'confidence' => null, 'method' => $method, 'review' => ['state' => 'accepted'],
                ],
            ],
            $attempt('incorrect', '14:10:00', 'SELECT * FROM customers JOIN orders USING (customer_id);'),
            $attempt('correct', '14:25:00', 'SELECT * FROM customers LEFT JOIN orders USING (customer_id);'),
        ];
    }
}
