<?php

namespace App\Study;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;

/**
 * Making flashcards with any AI (docs/specs/study-memory.md §4.5): the
 * student copies a prompt (resources/prompts/flashcards.md, with a topic's
 * key points and, if they choose, one of their notes), pastes the AI's reply
 * back, and keeps the cards they want. The cards are read with
 * App\Study\Capture, like the marks of a study session.
 */
final class CardMaker
{
    public const PROMPT = 'resources/prompts/flashcards.md';

    public const COUNTS = [5, 10, 15, 20];

    /** The most of a note's text the prompt carries, in characters. */
    public const NOTE_LIMIT = 12_000;

    /** The most of the student's cards listed so the AI doesn't repeat them. */
    public const KNOWN_LIMIT = 60;

    public function __construct(
        private Workspaces $workspaces,
        private Topics $topics,
        private Findings $findings,
        private Notes $notes,
        private Flashcards $flashcards,
    ) {}

    /** The prompt to copy: what to make, how, and the material to make it from. */
    public function prompt(Principal $by, string $workspaceId, ?string $topicId, int $count, ?string $noteId = null): string
    {
        $workspace = $this->workspaces->find($by, $workspaceId);
        $topics = $this->topics->list($by, $workspace->id);
        $topic = $topicId === null ? null : (collect($topics)->firstWhere('id', $topicId) ?? throw new NotFound);
        $count = in_array($count, self::COUNTS, true) ? $count : 10;

        $names = array_map(fn ($t) => $t->name, $topics);
        $fill = [
            '{{course}}' => $workspace->name,
            '{{count}}' => (string) $count,
            '{{topic}}' => $topic?->name ?? 'the topics of '.$workspace->name,
            '{{topic_mark}}' => $topic?->name ?? 'the topic',
            '{{topic_rule}}' => match (true) {
                $topic !== null => "Every card is about {$topic->name}: keep topic=\"{$topic->name}\" as it is.",
                $names !== [] => 'Put each card under one of these topics, with its name exactly as written: '.implode(', ', array_map(fn ($n) => "\"{$n}\"", $names)).'. If a card fits none of them, leave the topic out: <flashcard><front>…</front><back>…</back></flashcard>.',
                default => 'Name the topic of each card in a word or two, the same name for cards on the same topic.',
            },
        ];
        $template = (string) file_get_contents(base_path(self::PROMPT));
        $template = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $template);
        $parts = [trim(strtr($template, $fill))];

        $material = [];
        $findings = $this->findings->byTopic($by, $workspace->id);
        foreach ($topic === null ? $topics : [$topic] as $t) {
            $points = array_map(fn ($f) => '- '.$f->text, $findings[$t->id] ?? []);
            if ($points !== []) {
                $material[] = "### Key points on {$t->name}\n\n".implode("\n", $points);
            }
        }
        if ($noteId !== null) {
            $note = $this->notes->open($by, $noteId);
            $note->workspaceId === $workspace->id || throw new NotFound;
            $text = NoteDoc::markdown($note->doc ?? []);
            if (mb_strlen($text) > self::NOTE_LIMIT) {
                $text = rtrim(mb_substr($text, 0, self::NOTE_LIMIT))."\n\n(The rest of this note is left out.)";
            }
            $material[] = "### The student's note: {$note->displayTitle()}\n\n".($text !== '' ? $text : '(Empty.)');
        }
        $parts[] = "## The material\n\n".($material !== [] ? implode("\n\n", $material)
            : 'The student hasn\'t shared material for this yet. Make the cards from what a course like '.$workspace->name.' teaches about '.$fill['{{topic}}'].', keeping to the basics, and say at the end that they come from general knowledge, so the student checks them against the course.');

        $known = $this->flashcards->list($by, $workspace->id, $topic?->id);
        if ($known !== []) {
            $lines = array_map(fn ($card) => '- '.$card->front, array_slice($known, 0, self::KNOWN_LIMIT));
            if (count($known) > self::KNOWN_LIMIT) {
                $lines[] = '- … and '.(count($known) - self::KNOWN_LIMIT).' more';
            }
            $parts[] = "## Cards the student already has\n\n".implode("\n", $lines);
        }

        return implode("\n\n", $parts)."\n";
    }

    /**
     * The cards in the AI's reply, ready to review: each with its topic (the
     * one chosen, or the one it names, or '' for none), whether the student
     * already has it, and whether it's ticked.
     *
     * @return list<array{front: string, back: string, topic_id: string, known: bool, include: bool, fingerprint: string}>
     */
    public function read(Principal $by, string $workspaceId, string $text, ?string $topicId = null): array
    {
        $names = [];
        foreach ($this->topics->list($by, $workspaceId) as $topic) {
            $names[mb_strtolower($topic->name)] = $topic->id;
        }
        $known = $this->flashcards->fingerprints($by, $workspaceId);
        $cards = [];
        foreach (Capture::parse($text) as $item) {
            if ($item['kind'] !== 'flashcard') {
                continue;
            }
            $isKnown = isset($known[$item['fingerprint']]);
            $cards[] = [
                'front' => $item['front'], 'back' => $item['back'],
                'topic_id' => $topicId ?? ($item['topic'] !== null ? ($names[mb_strtolower($item['topic'])] ?? '') : ''),
                'known' => $isKnown, 'include' => ! $isKnown, 'fingerprint' => $item['fingerprint'],
            ];
        }

        return $cards;
    }

    /**
     * Saves the ticked cards as made with an AI.
     *
     * @return array{saved: int, failed: list<array{0: int, 1: string}>}
     */
    public function save(Principal $by, string $workspaceId, array $cards): array
    {
        $saved = 0;
        $failed = [];
        foreach ($cards as $index => $card) {
            if (! is_array($card) || ! ($card['include'] ?? false) || ($card['known'] ?? false)) {
                continue;
            }
            $topicId = is_string($card['topic_id'] ?? null) && $card['topic_id'] !== '' ? $card['topic_id'] : null;
            try {
                $this->flashcards->add($by, $workspaceId, $topicId, $card['front'] ?? '', $card['back'] ?? '', 'ai');
                $saved++;
            } catch (Unprocessable $e) {
                $fields = $e->details['fields'] ?? [];
                $failed[] = [$index, $fields === [] ? $e->getMessage() : reset($fields)[0]];
            } catch (NotFound) {
                $failed[] = [$index, 'Its topic no longer exists.'];
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }
}
