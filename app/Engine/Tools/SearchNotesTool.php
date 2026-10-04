<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\NoteDoc;
use App\Study\Notes;

/** The notes that mention some words, with a snippet around the first match in each. */
final class SearchNotesTool implements Tool
{
    public function __construct(private Notes $notes) {}

    public function name(): string
    {
        return 'search_notes';
    }

    public function description(): string
    {
        return 'Finds the student\'s notes that mention some words (in the title or the text), with a short snippet of each around the match. Use it when you don\'t know which note holds something.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'A word or a few words to look for.']], 'required' => ['query'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $query = mb_strtolower(Lookup::text($input, 'query') ?? '');
        if (mb_strlen($query) < 2) {
            return 'Give a word or two to look for.';
        }
        $rows = [];
        foreach ($this->notes->list($by, $context->workspaceId) as $note) {
            try {
                $text = NoteDoc::plain($this->notes->open($by, $note->id)->doc ?? []);
            } catch (NotFound) {
                continue;
            }
            $title = $note->displayTitle();
            $at = mb_stripos($text, $query);
            if ($at === false && mb_stripos($title, $query) === false) {
                continue;
            }
            $snippet = $at === false ? mb_substr($text, 0, 160) : mb_substr($text, max(0, $at - 80), 220);
            $rows[] = ['note' => $title, 'snippet' => trim((string) preg_replace('/\s+/u', ' ', $snippet))];
            if (count($rows) === 10) {
                break;
            }
        }

        return $rows === [] ? "No note mentions \"{$query}\"." : Lookup::json($rows);
    }
}
