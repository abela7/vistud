<?php

declare(strict_types=1);

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Platform\Ids;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\MarkdownDoc;
use App\Study\NoteDetails;
use App\Study\Notes;

/**
 * Saves a useful study note, explanation, key point, or resource from a study session (or topic/assignment chat)
 * directly into an assignment's folder and log so it is waiting for the student on the assignment page.
 */
final class SaveToAssignmentTool implements Tool
{
    public const MAX_TEXT = 12_000;

    public function __construct(
        private Activities $activities,
        private Notes $notes,
        private AssignmentLog $log,
    ) {}

    public function name(): string
    {
        return 'save_to_assignment';
    }

    public function description(): string
    {
        return 'Saves useful study notes, examples, or resources to an assignment\'s folder and log.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignment' => ['type' => 'string', 'description' => 'Assignment title (optional if only one is open).'],
                'title' => ['type' => 'string', 'description' => 'Note title.'],
                'text' => ['type' => 'string', 'description' => 'Markdown content to save.'],
            ],
            'required' => ['title', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $title = trim(Lookup::text($input, 'title') ?? '');
        $text = trim(Lookup::text($input, 'text') ?? '');
        if ($title === '') {
            return 'Give a short title for what to save to the assignment.';
        }
        if ($text === '') {
            return 'Give the Markdown text to save to the assignment.';
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return 'Keep the saved note to '.self::MAX_TEXT.' characters at a time.';
        }

        $activity = $this->resolveAssignment($by, $context, Lookup::text($input, 'assignment'));
        if (is_string($activity)) {
            return $activity;
        }

        $blocks = MarkdownDoc::blocks($text);
        if ($blocks === []) {
            return 'Nothing to write: the text had no words.';
        }

        $folder = $this->activities->folder($by, $activity->id);
        $cleanTitle = mb_substr($title, 0, Notes::MAX_TITLE);

        $existing = collect($this->notes->list($by, $context->workspaceId))
            ->first(fn (NoteDetails $n) => $n->trashedAt === null && $n->folderId === $folder->id && mb_strtolower($n->displayTitle()) === mb_strtolower($cleanTitle));

        if ($existing !== null) {
            $saved = $this->notes->append($by, $existing->id, $blocks);
            $note = $existing;
            $version = $saved->version;
        } else {
            $note = $this->notes->createWritten($by, 'folder', $folder->id, [
                'create_id' => Ids::new(),
                'title' => $cleanTitle,
                'doc' => ['type' => 'doc', 'content' => $blocks],
            ]);
            $version = 1;
        }

        $logText = mb_substr("Saved study note \"{$note->displayTitle()}\" to this assignment.", 0, 500);
        $entry = $this->log->add($by, $activity->id, $activity->stage, 'advice', $logText, byRole: 'tutor');

        $context->effects->wrote($note->id, $note->displayTitle(), $version);
        if ($context->activityId === $activity->id) {
            $context->effects->logEntry($entry->id, $entry->stage, $entry->kind, $entry->text);
        }

        return "Saved \"{$note->displayTitle()}\" to the assignment \"{$activity->title}\". It is now in the assignment's Files and notes and logged on the assignment.";
    }

    private function resolveAssignment(Principal $by, Context $context, ?string $wanted): ActivityDetails|string
    {
        if (($wanted === null || trim($wanted) === '') && $context->activityId !== null) {
            return $this->activities->find($by, $context->activityId);
        }

        $all = $this->activities->list($by, $context->workspaceId);
        $open = array_values(array_filter($all, fn (ActivityDetails $a) => $a->status !== 'done'));

        if ($wanted === null || trim($wanted) === '') {
            if (count($open) === 1) {
                return $open[0];
            }
            if ($open === []) {
                return 'There are no open assignments in this course.';
            }

            return 'Several assignments are open: '.implode('; ', array_map(fn (ActivityDetails $a) => $a->title, array_slice($open, 0, 6))).'. Say which assignment to save to.';
        }

        return Lookup::one($open !== [] ? $open : $all, $wanted, fn (ActivityDetails $a) => $a->title, 'assignment');
    }
}
