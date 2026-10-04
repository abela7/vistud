<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/** The last study sessions: when, on what, how long, and the tutor's summary and checkpoint of each. */
final class EarlierSessionsTool implements Tool
{
    public function __construct(private Sessions $sessions, private Topics $topics, private Notes $notes, private Files $files) {}

    public function name(): string
    {
        return 'earlier_sessions';
    }

    public function description(): string
    {
        return 'The last ten study sessions in this course: when each was, its topic, how long it lasted, the notes and files it used, and the summary and checkpoint its tutor left. Use it to pick up where an earlier session stopped, or to see whether a note was studied before.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $topics = collect($this->topics->list($by, $context->workspaceId))->pluck('name', 'id');
        $names = [];
        foreach ($this->notes->list($by, $context->workspaceId) as $note) {
            $names["note:{$note->id}"] = 'the note "'.$note->displayTitle().'"';
        }
        foreach ($this->files->list($by, $context->workspaceId) as $file) {
            $names["file:{$file->id}"] = 'the file "'.$file->fileName().'"';
        }
        $rows = [];
        foreach ($this->sessions->list($by, $context->workspaceId, 11) as $session) {
            if ($session->id === $context->sessionId) {
                continue;
            }
            $rows[] = array_filter([
                'on' => CarbonImmutable::parse($session->startedAt)->setTimezone($context->zone)->format('Y-m-d'),
                'topic' => $session->topicId !== null ? $topics[$session->topicId] ?? null : null,
                'studied' => SessionDetails::duration($session->studySeconds),
                'open_now' => $session->isOpen() ?: null,
                'used' => array_values(array_filter(array_map(fn (string $item) => $names[$item] ?? null, $session->material))) ?: null,
                'summary' => $session->summary,
                'checkpoint' => $session->checkpoint,
            ]);
        }

        return $rows === [] ? 'No earlier sessions in this course.' : Lookup::json(array_slice($rows, 0, 10));
    }
}
