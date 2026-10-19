<?php

namespace App\Mcp\Tools;

use App\Engine\Context\Stack;
use App\Mcp\ContextBuilder;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Sessions;
use Illuminate\Container\Container;

/**
 * The steps of an open study session an AI app takes (docs/specs/mcp-connections-plan.md §3.3): change its topic,
 * save where it stands, resume it after a quiet spell, end it, or read its brief. Each names the session by
 * `session_id`; an ended one is refused with what to do. One class, one tool per step (`$step`).
 */
final class SessionStepTool extends McpTool
{
    public const STEPS = ['set_session_topic', 'set_checkpoint', 'resume_session', 'end_session', 'study_brief'];

    public function __construct(private string $step) {}

    protected function info(): string
    {
        return $this->step;
    }

    public function description(): string
    {
        return match ($this->step) {
            'set_session_topic' => 'Changes the topic the open session is on, by topic_id from the topics tool. Use it when the student moves to another topic; ViStud records how long each topic was studied.',
            'set_checkpoint' => 'Saves where the session stands, in one or two sentences ("Finished round robin; next: priority scheduling"), so the next session picks up there. Save it at each turning point.',
            'resume_session' => 'Resumes a session paused because the chat was quiet for 30 minutes, so its clock runs again. A save to the session resumes it too.',
            'end_session' => 'Ends the study session. Give a short summary of what was covered and a checkpoint of where to pick up; both are kept for the next session. It answers the minutes studied.',
            default => 'The brief to teach from for the open session: the course, the student\'s preferences, the module with its topics, files and open questions, and the session so far. Read it before teaching.',
        };
    }

    protected function fields(): array
    {
        return match ($this->step) {
            'set_session_topic' => ['topic_id' => ['type' => 'string', 'description' => 'The topic\'s id, from the topics tool.']],
            'set_checkpoint' => ['checkpoint' => ['type' => 'string', 'description' => 'Where the session stands, at most '.Sessions::CHECKPOINT_LIMIT.' characters.']],
            'end_session' => [
                'summary' => ['type' => 'string', 'description' => 'What was covered, at most '.Sessions::SUMMARY_LIMIT.' characters.'],
                'checkpoint' => ['type' => 'string', 'description' => 'Where to pick up next time.'],
            ],
            default => [],
        };
    }

    protected function requiredFields(): array
    {
        return match ($this->step) {
            'set_session_topic' => ['topic_id'],
            'set_checkpoint' => ['checkpoint'],
            default => [],
        };
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $container = Container::getInstance();
        $sessionId = ContextBuilder::id($arguments, 'session_id') ?? throw new Unprocessable('session_required', 'missing: session_id. Call start_session or current_session.');
        $session = $container->make(ContextBuilder::class)->openSession($by, $sessionId);
        $sessions = $container->make(Sessions::class);
        $text = fn (string $key) => is_string($arguments[$key] ?? null) ? trim($arguments[$key]) : '';
        $done = ['type' => 'session', 'id' => $session->id];

        switch ($this->step) {
            case 'set_session_topic':
                $topicId = ContextBuilder::id($arguments, 'topic_id') ?? throw new Unprocessable('topic_required', 'missing: topic_id. Call topics for the ids.');
                $after = $sessions->setTopic($by, $session->id, $topicId, 'tutor');

                return $done + ['text' => 'The session is now on that topic.', 'structured' => ['topic_id' => $after->topicId], 'changed' => true];

            case 'set_checkpoint':
                $sessions->setCheckpoint($by, $session->id, $text('checkpoint'));

                return $done + ['text' => 'Checkpoint saved.', 'changed' => true];

            case 'resume_session':
                if ($session->state === 'running') {
                    return $done + ['text' => 'The session is already running.'];
                }
                $sessions->resume($by, $session->id);

                return $done + ['text' => 'The session is running again.', 'changed' => true];

            case 'end_session':
                if ($text('summary') !== '') {
                    // A summary the in-app tutor already wrote is kept; this one is added after it.
                    $summary = trim(($session->summary !== null ? $session->summary."\n\n" : '').$text('summary'));
                    $sessions->setSummary($by, $session->id, mb_substr($summary, 0, Sessions::SUMMARY_LIMIT));
                }
                if ($text('checkpoint') !== '') {
                    $sessions->setCheckpoint($by, $session->id, $text('checkpoint'));
                }
                $ended = $sessions->end($by, $session->id);
                $minutes = intdiv($ended->studySeconds, 60);

                return $done + ['text' => "Session ended: {$minutes} min studied.", 'structured' => ['study_minutes' => $minutes], 'changed' => true];

            default:
                return $done + ['text' => $container->make(Stack::class)->standing($by, $session)];
        }
    }
}
