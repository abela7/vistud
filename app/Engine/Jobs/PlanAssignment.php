<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Engine\Role;
use App\Platform\Access\Principal;
use App\Study\PlanMaker;

/**
 * An AI makes an assignment's plan while the student waits (the owner's ask, 2026-10-13): the rules of the plan prompt
 * (resources/prompts/assignment-plan.md) as its rules, and everything ViStud knows about the assignment as what it
 * reads (App\Study\PlanMaker::material), the same as the prompt the student can copy into any other AI. What comes
 * back is the AI's reply as written, to be read and checked like a pasted one; nothing is kept until the student
 * has looked it over. The tutor's model does it, or the one the student picks for this plan (Runner::answer).
 */
final class PlanAssignment extends Answering
{
    /** The longest answer, in tokens: a full plan of a big assignment, with room for a model that thinks first. */
    public const ANSWER_TOKENS = 12_000;

    /**
     * $brief is the student's pasted brief. It is not queued (a student waits for this), so it never leaves the request.
     */
    public function __construct(string $workspaceId, string $activityId, public readonly string $brief = '')
    {
        parent::__construct($workspaceId, 'activity', $activityId);
    }

    public function kind(): string
    {
        return 'plan_assignment';
    }

    /** A plan stands on the whole picture and on judgement: the tutor's model, not the reader's. */
    public function role(): Role
    {
        return Role::Tutor;
    }

    /**
     * @return string the AI's reply, with the plan in it
     *
     * @throws EngineFailed when there is no plan in the reply: a failure to try again
     */
    public function answer(Principal $by, Run $run): string
    {
        $maker = app(PlanMaker::class);
        $activityId = (string) $this->targetId;
        $reply = $run->ask($maker->rules($by, $activityId), $maker->material($by, $activityId, $this->brief), self::ANSWER_TOKENS)->whole();

        $read = PlanMaker::read($reply->text);
        if ($read['parts'] === [] && $read['steps'] === [] && $read['criteria'] === [] && $read['milestones'] === []) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer had no plan in it. Try again.');
        }

        return $reply->text;
    }
}
