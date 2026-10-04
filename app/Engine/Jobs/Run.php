<?php

namespace App\Engine\Jobs;

use App\Engine\Choices;
use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Reply;
use App\Engine\Request;
use App\Engine\Role;
use App\Engine\Usage;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;

/**
 * What a job is handed while it runs: its row, and the one way to call the engine. Every call goes out under the
 * model of the job's role, with the student's key and the training choice, is counted on the row (model, tokens,
 * cost), and is refused once the month's limit is reached.
 */
final class Run
{
    public function __construct(
        public readonly string $id,
        public readonly Role $role,
        private readonly Principal $by,
        private readonly Choices $choices,
        private readonly ?string $key,
        private readonly string $model,
        private readonly string $zone,
        private readonly Engine $engine,
        private readonly Usage $usage,
    ) {}

    /**
     * One call to the engine. $messages is one text for the user's turn, or a conversation in the OpenAI chat shape
     * (for a helper that looks things up, with $tools).
     *
     * @param  string|list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     *
     * @throws Unprocessable over the month's limit
     * @throws EngineFailed
     */
    public function ask(string $system, string|array $messages, int $maxTokens = 1000, array $tools = []): Reply
    {
        $scope = Guard::learner($this->by);
        $this->usage->refuseOverMonthCap($scope, $this->choices, $this->zone);
        $messages = is_string($messages) ? [['role' => 'user', 'content' => $messages]] : $messages;

        $reply = $this->engine->reply(new Request($this->model, $system, $messages, $tools, [], $maxTokens, $this->choices->noTraining, $this->key));

        LearnerTables::query($scope, 'engine_jobs')->where('id', $this->id)->update([
            'model' => $reply->model !== '' ? $reply->model : $this->model,
            'tokens_in' => DB::raw('tokens_in + '.$reply->tokensIn),
            'tokens_out' => DB::raw('tokens_out + '.$reply->tokensOut),
            'cost_micros' => DB::raw('cost_micros + '.(int) ($reply->costMicros ?? 0)),
        ]);

        return $reply;
    }

    /** Ends the run as skipped, with a code saying why (nothing to read, read already). */
    public function skip(string $code): never
    {
        throw new Skipped($code);
    }
}
