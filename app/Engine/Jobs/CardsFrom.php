<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Flashcards;
use App\Study\TopicDetails;
use App\Study\Topics;

/**
 * The reader makes flashcards from a file, a note or a topic (docs/specs/vistud-2-blueprint.md §3.6.5): the material in,
 * cards out, not yet kept. The student reviews them ticked, edits or unticks, and only the ones they keep are added
 * (as written by the AI), so nothing lands in their deck unseen. A card names its topic when it clearly belongs to one
 * of the module's topics, and goes to the topic the job was started on.
 */
final class CardsFrom extends Answering
{
    public const PROMPT = 'resources/prompts/reader-cards.md';

    public const TYPES = ['file', 'note', 'topic'];

    /** How many cards may be asked for. */
    public const COUNTS = [5, 8, 10, 15];

    public function __construct(string $workspaceId, string $sourceType, string $sourceId, public readonly int $count = 8)
    {
        in_array($sourceType, self::TYPES, true) || throw new NotFound;
        parent::__construct($workspaceId, $sourceType, $sourceId);
    }

    public function kind(): string
    {
        return 'cards_from';
    }

    /**
     * @return list<array{front: string, back: string, topic_id: ?string, topic: ?string}>
     */
    public function answer(Principal $by, Run $run): array
    {
        $material = app(Material::class);
        [$name, $text, $moduleId] = match ($this->targetType) {
            'file' => $material->file($by, $this->workspaceId, (string) $this->targetId),
            'note' => $material->note($by, $this->workspaceId, (string) $this->targetId),
            default => $material->topic($by, $this->workspaceId, (string) $this->targetId),
        };

        $topics = [];
        foreach (app(Topics::class)->list($by, $this->workspaceId) as $topic) {
            if ($moduleId === null || $topic->moduleId === $moduleId) {
                $topics[mb_strtolower($topic->name)] = $topic;
            }
        }
        $count = in_array($this->count, self::COUNTS, true) ? $this->count : 8;
        $about = $this->targetType === 'topic' ? 'the topic' : 'the '.$this->targetType;
        $message = "Make {$count} flashcards from {$about} \"{$name}\"."
            .($topics === [] ? '' : "\nTopics of the course: ".implode(' | ', array_map(fn ($topic) => $topic->name, $topics)))
            ."\nThe material is between the quotes.\n\"\"\"\n{$text}\n\"\"\"";

        $reply = $run->ask(self::rules(), $message, 3_000);
        $own = $this->targetType === 'topic' ? ($topics[mb_strtolower($name)] ?? null) : null;

        return self::parse($reply->text, $topics, $count, $own?->id);
    }

    /** The reader's rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * The cards in the answer, cleaned and checked: each with a front and a back within their limits, no front twice,
     * at most $count, each with the topic it names (matched to the course's) or $default.
     *
     * @param  array<string, TopicDetails>  $topics  by lower-case name
     * @return list<array{front: string, back: string, topic_id: ?string, topic: ?string}>
     *
     * @throws EngineFailed an answer that isn't an object with cards in it
     */
    public static function parse(string $answer, array $topics, int $count, ?string $default = null): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || ! is_array($data['cards'] ?? null)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be read. Try again.');
        }
        $clean = fn (mixed $value, int $limit) => is_string($value) ? mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', $value)), 0, $limit) : '';

        $cards = [];
        $seen = [];
        foreach ($data['cards'] as $item) {
            if (! is_array($item) || count($cards) >= $count) {
                continue;
            }
            $front = $clean($item['front'] ?? null, Flashcards::MAX_FRONT);
            $back = $clean($item['back'] ?? null, Flashcards::MAX_BACK);
            if ($front === '' || $back === '' || isset($seen[mb_strtolower($front)])) {
                continue;
            }
            $seen[mb_strtolower($front)] = true;
            $topic = is_string($item['topic'] ?? null) ? ($topics[mb_strtolower(trim($item['topic']))] ?? null) : null;
            $cards[] = ['front' => $front, 'back' => $back, 'topic_id' => $topic?->id ?? $default, 'topic' => $topic?->name];
        }
        if ($cards === []) {
            throw new EngineFailed('engine_unreadable', 'The AI made no cards from that. Try again.');
        }

        return $cards;
    }
}
