<?php

namespace App\Engine\Tools;

use App\Engine\Context\Stack;

/**
 * Where a chat stands: the workspace it is in, the module and session when there are ones, the folder the session
 * studies in (docs/specs/vistud-2-blueprint.md, Phase 9: the tools default to it), and the student's time zone; and
 * what the tools did in the course meanwhile (Effects), for the chat to tell. The chat's thread, when there is one,
 * is where the student agrees to what can't be undone (Agreement). The model whose call a tool is running, when known,
 * goes with what it judged into the journal (judge()).
 */
final readonly class Context
{
    public function __construct(public string $workspaceId, public ?string $moduleId, public ?string $sessionId, public string $zone, public Effects $effects = new Effects, public ?string $folderId = null, public ?string $threadId = null, public ?string $model = null, public ?string $activityId = null) {}

    /** The same chat, for a call made by `$model` (a fallback may answer instead of the chosen one); the effects are shared. */
    public function by(string $model): self
    {
        return new self($this->workspaceId, $this->moduleId, $this->sessionId, $this->zone, $this->effects, $this->folderId, $this->threadId, $model, $this->activityId);
    }

    /**
     * Who judged what the tutor records in this chat, for the journal: the model, the version of the tutor's rules it
     * was given, and that it came through ViStud's own chat.
     *
     * @return array{model: string, prompt: string, via: string}
     */
    public function judge(): array
    {
        return ['model' => $this->model !== null && $this->model !== '' ? $this->model : 'unknown', 'prompt' => Stack::promptVersion(), 'via' => 'chat'];
    }
}
