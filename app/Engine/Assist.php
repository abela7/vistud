<?php

namespace App\Engine;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;

/**
 * The quick jobs behind the ✦ menu (docs/specs/vistud-2-blueprint.md §3.6.5): each puts one small task to the helper on
 * the thing the student pointed at, and reads the answer back as something a screen can show as a proposal. It returns
 * what the helper proposes and changes nothing: the student keeps it or discards it. A card or a question is read through
 * the services, so only the student's own can be asked about.
 */
final class Assist
{
    /** What may be done to a card, a question and a selection, and the helper's task for each. */
    public const CARD = [
        'improve' => 'Improve this flashcard: make the front a clearer question and the back a more precise answer, keeping its meaning and its terms.',
        'shorter' => 'Make this flashcard shorter: a tighter front, and a back of a sentence or two, keeping its meaning.',
        'fix' => 'Fix the wording of this flashcard: spelling, grammar and clarity only. Do not change what it asks or answers.',
        'more' => 'Write two more flashcards like this one: same subject and style, each about a different idea in the same material, neither repeating this card.',
    ];

    public const QUESTION = [
        'clarify' => 'Rewrite this question so it is clear and specific, keeping the student\'s meaning and terms. Answer with the question only.',
        'split' => 'This question asks more than one thing. Split it into two separate questions, each complete on its own, one per line. Answer with the two questions only.',
    ];

    public const SELECTION = [
        'explain' => 'Explain this simply for a student, in a few sentences, using plain words.',
        'shorten' => 'Shorten this text, keeping everything important. Answer with the shortened text only.',
        'fix' => 'Fix the spelling, grammar and clarity of this text without changing its meaning. Answer with the corrected text only.',
    ];

    public function __construct(private Helper $helper, private Flashcards $flashcards, private Questions $questions, private Folders $folders, private Files $files, private Notes $notes, private Modules $modules) {}

    /**
     * What the helper makes of a card: for improve, shorter and fix the card as it would be; for more, two new cards.
     *
     * @return array{before: array{front: string, back: string}, cards: list<array{front: string, back: string}>}
     *
     * @throws NotFound not the student's card
     * @throws EngineFailed an answer that has no card in it
     */
    public function card(Principal $by, string $id, string $action): array
    {
        $task = self::CARD[$action] ?? throw new NotFound;
        $card = $this->flashcards->find($by, $id);
        $form = "Answer exactly in this form, with nothing else:\nFront: …\nBack: …".($action === 'more' ? "\n\n(then a blank line and the second card in the same form)" : '');
        $answer = $this->helper->quick($by, "{$task}\n{$form}", "Front: {$card->front}\nBack: {$card->back}", $card->moduleId);
        $cards = self::cards($answer, $action === 'more' ? 2 : 1);

        return ['before' => ['front' => $card->front, 'back' => $card->back], 'cards' => $cards];
    }

    /**
     * What the helper makes of a question: clarify gives one question in its place; split gives two.
     *
     * @return array{before: string, questions: list<string>}
     *
     * @throws NotFound
     * @throws EngineFailed
     */
    public function question(Principal $by, string $id, string $action): array
    {
        $task = self::QUESTION[$action] ?? throw new NotFound;
        $question = $this->questions->find($by, $id);
        $answer = $this->helper->quick($by, $task, $question->text, $question->moduleId);
        $lines = self::lines($answer);
        $lines = array_slice($lines, 0, $action === 'split' ? 2 : 1);
        if (count($lines) < ($action === 'split' ? 2 : 1)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be used. Try again.');
        }

        return ['before' => $question->text, 'questions' => $lines];
    }

    /**
     * What the helper makes of a selection of the student's text.
     *
     * @throws NotFound an action it doesn't know
     * @throws EngineFailed
     */
    public function selection(Principal $by, string $text, string $action, ?string $moduleId = null): string
    {
        $task = self::SELECTION[$action] ?? throw new NotFound;

        return $this->helper->quick($by, $task, $text, $moduleId);
    }

    /**
     * Where the things in a folder belong, in the course's modules: the helper's words, to be read, not applied.
     *
     * @throws NotFound not the student's folder
     * @throws EngineFailed
     */
    public function where(Principal $by, string $folderId): string
    {
        $folder = $this->folders->find($by, $folderId);
        $names = [];
        foreach ($this->files->list($by, $folder->workspaceId) as $file) {
            if ($file->folderId === $folder->id && $file->trashedAt === null) {
                $names[] = $file->fileName();
            }
        }
        foreach ($this->notes->list($by, $folder->workspaceId) as $note) {
            if ($note->folderId === $folder->id && $note->trashedAt === null) {
                $names[] = $note->displayTitle();
            }
        }
        $modules = array_map(fn ($module) => $module->title, $this->modules->list($by, $folder->workspaceId));
        if ($names === []) {
            return 'There is nothing in this folder yet.';
        }
        $thing = "The folder: {$folder->name}\nIts files and notes:\n- ".implode("\n- ", $names)."\n\nThe modules of the course:\n- ".($modules === [] ? '(none yet)' : implode("\n- ", $modules));

        return $this->helper->quick($by, 'Say which module each of the folder\'s files and notes belongs in, one per line as "name → module". Say "stays here" for one that already fits where it is.', $thing, $folder->moduleId);
    }

    /**
     * The cards in an answer written as "Front: … Back: …", one after another.
     *
     * @return list<array{front: string, back: string}>
     *
     * @throws EngineFailed no card in it
     */
    public static function cards(string $answer, int $most = 2): array
    {
        // Bold labels are the same labels.
        $answer = str_replace(['**', '__'], '', $answer);
        preg_match_all('/Front:\s*(.+?)\s*\R\s*Back:\s*(.+?)(?=\R\s*Front:|\z)/is', $answer, $found, PREG_SET_ORDER);
        $cards = [];
        foreach ($found as [, $front, $back]) {
            $front = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $front)), 0, Flashcards::MAX_FRONT);
            $back = mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', $back)), 0, Flashcards::MAX_BACK);
            if ($front !== '' && $back !== '' && count($cards) < $most) {
                $cards[] = ['front' => $front, 'back' => $back];
            }
        }
        if ($cards === []) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be used. Try again.');
        }

        return $cards;
    }

    /**
     * The non-empty lines of an answer, without a bullet or a number in front.
     *
     * @return list<string>
     */
    public static function lines(string $answer): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($answer)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line));
            $line = trim((string) preg_replace('/^(?:Question\s*\d*|Q\d*)\s*:\s*/iu', '', $line));
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 500);
            }
        }

        return $lines;
    }
}
