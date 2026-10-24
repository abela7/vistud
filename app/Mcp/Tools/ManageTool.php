<?php

namespace App\Mcp\Tools;

use App\Engine\Settings;
use App\Mcp\Confirmations;
use App\Platform\Access\Principal;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Illuminate\Container\Container;

/**
 * A change only the student can make (docs/specs/mcp-connections-plan.md §3.1, M6.5): a rename, forgetting or
 * remembering a line about them, or replacing a whole note. The tool changes nothing. It stores the change
 * (App\Mcp\Confirmations) and answers with a ViStud link the student opens and says Yes on; the app is told to give
 * them the link and wait. Only at level "full". One class, one tool per change (`$change`).
 *
 * `remember` is the one exception (the owner's ask, 2026-10-24): it keeps the line at once, as ViStud's own tutor does,
 * while the student lets the tutor remember (Settings › AI); the student sees and deletes the lines in Settings.
 */
final class ManageTool extends McpTool
{
    public function __construct(private string $change) {}

    protected function info(): string
    {
        return $this->change;
    }

    public function description(): string
    {
        if ($this->change === 'remember') {
            return 'Keeps one short line (at most '.TutorMemories::MAX_TEXT.' characters) about the student for every future tutor: something lasting that should change how they are taught (how they learn, a mistake they repeat, a goal or exam date). Not course content, and never a password, health or money unless they ask. Saved at once while the student lets tutors remember; say what you kept.';
        }
        $what = match ($this->change) {
            'rename_topic' => 'rename a topic',
            'rename_module' => 'rename a module',
            'rename_folder' => 'rename a folder',
            'rename_course' => 'rename a course',
            'rename_note' => 'rename a note',
            'forget_memory' => 'forget a line ViStud remembers about the student',
            'remember' => 'remember a line about the student, for every future lesson',
            default => 'replace the whole text of a note',
        };

        return "Asks to {$what}. This changes nothing by itself: it answers with a ViStud link. Give the link to the student; the change happens only if they open it and press Yes there. Calling the tool again is not consent.";
    }

    protected function fields(): array
    {
        $id = fn (string $from) => ['type' => 'string', 'description' => "The id, from {$from}."];

        return match ($this->change) {
            'rename_topic' => ['topic_id' => $id('topics'), 'new_name' => ['type' => 'string', 'description' => 'The new name.']],
            'rename_module' => ['module_id' => $id('course_overview'), 'new_title' => ['type' => 'string', 'description' => 'The new title.']],
            'rename_folder' => ['folder_id' => $id('files'), 'new_name' => ['type' => 'string', 'description' => 'The new name.']],
            'rename_course' => ['course_id' => $id('courses'), 'new_name' => ['type' => 'string', 'description' => 'The new name.']],
            'rename_note' => ['note_id' => $id('notes'), 'new_title' => ['type' => 'string', 'description' => 'The new title.']],
            'forget_memory' => ['memory_id' => $id('memories')],
            'remember' => [
                'text' => ['type' => 'string', 'description' => 'One short line about the student, in plain words, at most '.TutorMemories::MAX_TEXT.' characters.'],
                'course_id' => ['type' => 'string', 'description' => 'The course it is for; leave out to remember it for every course.'],
                'replaces_memory_id' => ['type' => 'string', 'description' => 'The id, from memories, of the line this one replaces.'],
            ],
            default => ['note_id' => $id('notes'), 'text' => ['type' => 'string', 'description' => 'The note\'s whole new text, in Markdown.']],
        };
    }

    protected function requiredFields(): array
    {
        return match ($this->change) {
            'rename_topic', 'rename_module', 'rename_folder', 'rename_course', 'rename_note' => [Confirmations::TOOLS[$this->change]['id'], Confirmations::TOOLS[$this->change]['text']],
            'forget_memory' => ['memory_id'],
            'remember' => ['text'],
            default => ['note_id', 'text'],
        };
    }

    /** The tool carries the hint Claude Code reads to stop and ask the student itself. */
    public function toArray(): array
    {
        $result = parent::toArray();
        if ($this->change !== 'remember') {
            $result['_meta'] = (array) ($result['_meta'] ?? []) + ['anthropic/requiresUserInteraction' => true];
        }

        return $result;
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        if ($this->change === 'remember') {
            return $this->remember($by, $arguments);
        }
        $asked = Container::getInstance()->make(Confirmations::class)->ask($by, $connection ?? throw new \LogicException('A change needs a connection.'), $this->change, $arguments);

        return [
            'text' => 'Not done yet. Tell the student to open this link in ViStud and press Yes to make the change: '.$asked['url'].' The link works for '.Confirmations::MINUTES.' minutes. Do not call this tool again to confirm it: only the student can.',
            'structured' => ['confirm_url' => $asked['url'], 'expires_in_minutes' => Confirmations::MINUTES],
            'type' => 'confirmation',
            'id' => $asked['id'],
            'status' => 'pending_confirmation',
        ];
    }

    /**
     * Keeps the line now, for one course or for all, in place of `replaces_memory_id` when given. A line too long, or no
     * room left, comes back as a rejected save that says so.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function remember(Principal $by, array $arguments): array
    {
        $container = Container::getInstance();
        if (! $container->make(Settings::class)->get($by)->tutorRemembers) {
            return ['text' => 'Not kept: the student has turned off tutors remembering things (Settings › AI). Do not say you will remember it.', 'status' => 'unchanged'];
        }
        $course = is_string($arguments['course_id'] ?? null) && $arguments['course_id'] !== '' ? $container->make(Workspaces::class)->find($by, $arguments['course_id'])->id : null;
        $replaces = is_string($arguments['replaces_memory_id'] ?? null) && $arguments['replaces_memory_id'] !== '' ? $arguments['replaces_memory_id'] : null;
        $kept = $container->make(TutorMemories::class)->add($by, $course, $arguments['text'] ?? null, 'tutor', replacesId: $replaces);

        return [
            'text' => 'Kept '.($course === null ? 'for all courses' : 'for this course').': "'.$kept->text.'". The student sees it in ViStud, Settings › AI, and can change or delete it. Say so in a line.',
            'type' => 'memory',
            'id' => $kept->id,
            'changed' => true,
            'structured' => ['memory_id' => $kept->id],
        ];
    }
}
