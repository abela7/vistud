<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/**
 * The earlier study sessions: when, on what, how long, what each used, and the summary and checkpoint of each;
 * the last ten, or up to thirty, narrowed to a module or a topic when asked.
 */
final class EarlierSessionsTool implements Tool
{
    public const MOST = 30;

    public function __construct(private Sessions $sessions, private Topics $topics, private Modules $modules, private Notes $notes, private Files $files) {}

    public function name(): string
    {
        return 'earlier_sessions';
    }

    public function description(): string
    {
        return 'The student\'s earlier study sessions in this course, newest first: when each was, its module and topic, how long it lasted, the notes and files it used, and the summary and checkpoint of each. The last ten by default, up to thirty; narrowed to one module or one topic when asked. Use it to pick up where an earlier session stopped, to see whether a note was studied before, or to go over a whole module before an exam.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to.'],
            'topic' => ['type' => 'string', 'description' => 'A topic\'s name (or part of it) to limit to.'],
            'limit' => ['type' => 'integer', 'description' => 'How many sessions at most, 1 to '.self::MOST.' (10 when left out).'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $topicList = $this->topics->list($by, $context->workspaceId);
        $moduleList = $this->modules->list($by, $context->workspaceId);
        $topics = collect($topicList)->pluck('name', 'id');
        $modules = collect($moduleList)->pluck('title', 'id');
        $limit = max(1, min(self::MOST, (int) ($input['limit'] ?? 10)));
        $onlyTopic = null;
        if (($wanted = Lookup::text($input, 'topic')) !== null) {
            $topic = Lookup::one($topicList, $wanted, fn ($t) => $t->name, 'topic');
            if (is_string($topic)) {
                return $topic;
            }
            $onlyTopic = $topic->id;
        }
        $onlyModule = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($moduleList, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $onlyModule = $module->id;
        }
        $names = [];
        foreach ($this->notes->list($by, $context->workspaceId) as $note) {
            $names["note:{$note->id}"] = 'the note "'.$note->displayTitle().'"';
        }
        foreach ($this->files->list($by, $context->workspaceId) as $file) {
            $names["file:{$file->id}"] = 'the file "'.$file->fileName().'"';
        }

        $sessions = $onlyModule !== null
            ? $this->sessions->forModule($by, $context->workspaceId, $onlyModule)
            : $this->sessions->list($by, $context->workspaceId, $onlyTopic !== null ? 200 : $limit + 1);
        $rows = [];
        foreach ($sessions as $session) {
            if ($session->id === $context->sessionId || ($onlyTopic !== null && $session->topicId !== $onlyTopic)) {
                continue;
            }
            $moduleId = $session->moduleId ?? ($session->topicId !== null ? collect($topicList)->firstWhere('id', $session->topicId)?->moduleId : null);
            $rows[] = array_filter([
                'on' => CarbonImmutable::parse($session->startedAt)->setTimezone($context->zone)->format('Y-m-d'),
                'module' => $moduleId !== null ? $modules[$moduleId] ?? null : null,
                'topic' => $session->topicId !== null ? $topics[$session->topicId] ?? null : null,
                'studied' => SessionDetails::duration($session->studySeconds),
                'open_now' => $session->isOpen() ?: null,
                'used' => array_values(array_filter(array_map(fn (string $item) => $names[$item] ?? null, $session->material))) ?: null,
                'summary' => $session->summary,
                'checkpoint' => $session->checkpoint,
            ]);
            if (count($rows) >= $limit) {
                break;
            }
        }

        $where = $onlyTopic !== null || $onlyModule !== null ? ' there' : ' in this course';

        return $rows === [] ? "No earlier sessions{$where}." : Lookup::json($rows);
    }
}
