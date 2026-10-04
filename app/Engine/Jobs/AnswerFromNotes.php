<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Questions;

/**
 * The reader answers a stuck question from the student's own notes (docs/specs/vistud-2-blueprint.md §3.6.5): the
 * question and the module's notes and file summaries in, an answer out, only from what they say. When they don't say, it
 * says so rather than fill the gap. The answer is proposed; the student keeps it on the question or discards it.
 */
final class AnswerFromNotes extends Answering
{
    public const PROMPT = 'resources/prompts/reader-answer.md';

    public const MAX_ANSWER = 700;

    public function __construct(string $workspaceId, string $questionId)
    {
        parent::__construct($workspaceId, 'question', $questionId);
    }

    public function kind(): string
    {
        return 'answer_from_notes';
    }

    /**
     * @return array{answer: string, from: list<string>}
     *
     * @throws Unprocessable the notes don't answer it
     */
    public function answer(Principal $by, Run $run): array
    {
        $question = app(Questions::class)->find($by, (string) $this->targetId);
        $question->workspaceId === $this->workspaceId || throw new NotFound;
        $material = app(Material::class);
        [$notes] = $material->notesOf($by, $this->workspaceId, $question->moduleId);
        $files = $question->moduleId === null ? [] : $material->digestsOf($by, $question->moduleId);
        if ($notes === '' && $files === []) {
            throw new Unprocessable('no_notes', 'There are no notes or files in this module to answer from yet.');
        }

        $message = "The question: {$question->text}\n\nThe student's notes and file summaries are between the quotes.\n\"\"\"\n"
            .($notes !== '' ? $notes : '(no notes)')
            .($files === [] ? '' : "\n\nFiles:\n- ".implode("\n- ", $files))
            ."\n\"\"\"";
        $reply = $run->ask(self::rules(), $message, 1_000);

        return self::parse($reply->text);
    }

    /** The reader's rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * @return array{answer: string, from: list<string>}
     *
     * @throws EngineFailed an answer that can't be read
     * @throws Unprocessable the notes don't answer it
     */
    public static function parse(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be read. Try again.');
        }
        $text = is_string($data['answer'] ?? null) ? mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', $data['answer'])), 0, self::MAX_ANSWER) : '';
        if (($data['found'] ?? true) === false || $text === '') {
            throw new Unprocessable('not_in_notes', 'Your notes don\'t answer this yet. Add the material, or ask the tutor.');
        }
        $from = [];
        foreach (is_array($data['from'] ?? null) ? $data['from'] : [] as $title) {
            $title = is_string($title) ? mb_substr(trim($title), 0, 120) : '';
            if ($title !== '' && ! in_array($title, $from, true) && count($from) < 5) {
                $from[] = $title;
            }
        }

        return ['answer' => $text, 'from' => $from];
    }
}
