<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Findings;
use App\Study\Topics;

/** The "must know" lines the student (or an earlier session) pinned to a topic, with where each came from. */
final class FindingsTool implements Tool
{
    public function __construct(private Findings $findings, private Topics $topics) {}

    public function name(): string
    {
        return 'findings';
    }

    public function description(): string
    {
        return 'The short "must know" lines kept on one topic (or on every topic), each with its source (a note or file, and where in it) and whether the student or an earlier study session wrote it.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['topic' => ['type' => 'string', 'description' => 'The topic\'s name (or part of it); leave out for all topics.']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $topics = $this->topics->list($by, $context->workspaceId);
        $byTopic = $this->findings->byTopic($by, $context->workspaceId);
        $only = null;
        if (($wanted = Lookup::text($input, 'topic')) !== null) {
            $topic = Lookup::one($topics, $wanted, fn ($t) => $t->name, 'topic');
            if (is_string($topic)) {
                return $topic;
            }
            $only = $topic->id;
        }
        $rows = [];
        foreach ($topics as $topic) {
            if ($only !== null && $topic->id !== $only) {
                continue;
            }
            foreach ($byTopic[$topic->id] ?? [] as $finding) {
                $rows[] = array_filter([
                    'topic' => $topic->name,
                    'finding' => $finding->text,
                    'source' => $finding->sourceName !== null ? trim($finding->sourceName.($finding->locator ? ", {$finding->locator}" : '')) : null,
                    'by' => $finding->author === 'ai' ? 'a study session' : 'the student',
                ]);
            }
        }

        return $rows === [] ? 'No findings kept'.($only !== null ? ' on that topic' : '').' yet.' : Lookup::json(array_slice($rows, 0, 80));
    }
}
