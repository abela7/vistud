<?php

namespace App\Mcp\Tools;

use App\Mcp\Confirmations;
use App\Platform\Access\Principal;
use Illuminate\Container\Container;

/**
 * A change only the student can make (docs/specs/mcp-connections-plan.md §3.1, M6.5): a rename, forgetting or
 * remembering a line about them, or replacing a whole note. The tool changes nothing. It stores the change
 * (App\Mcp\Confirmations) and answers with a ViStud link the student opens and says Yes on; the app is told to give
 * them the link and wait. Only at level "full". One class, one tool per change (`$change`).
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
                'text' => ['type' => 'string', 'description' => 'One short line about the student, in plain words.'],
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
        $result['_meta'] = (array) ($result['_meta'] ?? []) + ['anthropic/requiresUserInteraction' => true];

        return $result;
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $asked = Container::getInstance()->make(Confirmations::class)->ask($by, $connection ?? throw new \LogicException('A change needs a connection.'), $this->change, $arguments);

        return [
            'text' => 'Not done yet. Tell the student to open this link in ViStud and press Yes to make the change: '.$asked['url'].' The link works for '.Confirmations::MINUTES.' minutes. Do not call this tool again to confirm it: only the student can.',
            'structured' => ['confirm_url' => $asked['url'], 'expires_in_minutes' => Confirmations::MINUTES],
            'type' => 'confirmation',
            'id' => $asked['id'],
            'status' => 'pending_confirmation',
        ];
    }
}
