<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Lookup;
use App\Engine\Tools\Tool;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Memory;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;

/**
 * Finds a word across every course, by name: topics, notes (by title), files, questions and assignments, each with its
 * course. Names only, so it stays quick after years of material; the words inside notes are search_notes' in one course.
 */
final class SearchEverythingTool implements Tool
{
    public const MAX = 25;

    public function __construct(private Courses $courses, private Topics $topics, private Notes $notes, private Files $files, private Questions $questions, private Activities $activities, private Memory $memory) {}

    public function name(): string
    {
        return 'search_everything';
    }

    public function description(): string
    {
        return 'Finds a word or two across every course, in the names of topics, notes, files, questions and assignments; each match with its course. To look inside notes\' text, use search_notes in that course.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'A word or two to find.'],
        ], 'required' => ['query'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $query = Lookup::text($input, 'query') ?? '';
        if (mb_strlen($query) < 2) {
            return 'Give a word or two to find.';
        }
        $fits = fn (string $name) => mb_stripos($name, $query) !== false;
        $rows = [];
        $more = 0;
        $add = function (string $course, string $kind, string $name) use (&$rows, &$more) {
            count($rows) < self::MAX ? $rows[] = ['course' => $course, 'kind' => $kind, 'name' => $name] : $more++;
        };
        // The journal is read once for every course's topics and questions.
        $snapshot = $this->memory->snapshot(Guard::learner($by));
        foreach ($this->courses->all($by) as $course) {
            foreach ($this->topics->list($by, $course->id, $snapshot) as $topic) {
                $fits($topic->name) && $add($course->name, 'topic', Topics::cleanMarkdown($topic->name));
            }
            foreach ($this->notes->list($by, $course->id) as $note) {
                $fits($note->displayTitle()) && $add($course->name, 'note', $note->displayTitle());
            }
            foreach ($this->files->list($by, $course->id) as $file) {
                $fits($file->fileName()) && $add($course->name, 'file', $file->fileName());
            }
            foreach ($this->questions->list($by, $course->id, snapshot: $snapshot) as $question) {
                $fits($question->text) && $add($course->name, 'question', mb_strimwidth($question->text, 0, 160, '…'));
            }
            foreach ($this->activities->list($by, $course->id) as $activity) {
                $fits($activity->title) && $add($course->name, $activity->kind, $activity->title);
            }
        }

        return $rows === [] ? "Nothing in any course is named with \"{$query}\". To look inside notes' text, use search_notes in a course."
            : Lookup::json($rows).($more > 0 ? "\n({$more} more: ask with more words, or look in one course.)" : '');
    }
}
