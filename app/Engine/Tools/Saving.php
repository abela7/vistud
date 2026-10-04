<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Capture;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WriteBack;

/**
 * What the save tools share (make_flashcards, save_key_points, add_questions): the items go straight into the
 * course through the write-back, as kept marks do, so the same limits, topics and evidence apply and the same
 * item saved twice in a session is saved once. An item goes to the topic it names when the course has it, else
 * to the session's topic; the tutor never makes topics by saving (set_topic and add_topics do, when the student
 * agrees). Without either, a flashcard or a question goes under the session's module with no topic, and a key
 * point waits for a topic.
 */
abstract class Saving implements Tool
{
    /** The kinds that can be saved without a topic, under the session's module. */
    private const LOOSE = ['flashcard', 'question'];

    public function __construct(private WriteBack $writeBack, private Sessions $sessions, private Topics $topics, private Modules $modules) {}

    /**
     * @param  list<array{topic: mixed, fields: array<string, mixed>}>  $wanted
     * @param  array{0: string, 1: string}  $words  one, many: "flashcard", "flashcards"
     */
    protected function save(Principal $by, Context $context, string $kind, array $wanted, array $words): string
    {
        if ($context->sessionId === null) {
            return 'Saving needs a study session: ask the student to start one.';
        }
        if ($wanted === []) {
            return "Nothing to save: send at least one {$words[0]}.";
        }
        $session = $this->sessions->find($by, $context->sessionId);
        $names = [];
        foreach ($this->topics->list($by, $context->workspaceId) as $topic) {
            $names[mb_strtolower($topic->name)] = $topic->name;
        }
        $fallback = $session->topicId !== null ? collect($this->topics->list($by, $context->workspaceId))->firstWhere('id', $session->topicId)?->name : null;

        $items = [];
        $empty = 0;
        $loose = in_array($kind, self::LOOSE, true);
        // Topic names given that the course doesn't have, and key points with no topic to go to.
        $unknown = [];
        $without = 0;
        foreach (array_slice($wanted, 0, 10) as $one) {
            $named = is_string($one['topic'] ?? null) ? trim($one['topic']) : '';
            if ($named !== '' && ! isset($names[mb_strtolower($named)])) {
                $unknown[$named] = true;
            }
            $topic = $names[mb_strtolower($named)] ?? $fallback;
            if ($topic === null && ! $loose) {
                $without++;

                continue;
            }
            $item = Capture::from($kind, $topic, $one['fields']);
            $item === null ? $empty++ : $items[] = $item;
        }
        $unknownWords = '"'.implode('", "', array_keys($unknown)).'"';
        if ($items === []) {
            return $without > 0
                ? ucfirst($words[1]).' need a topic, and this session has none yet'.($unknown !== [] ? " (the course has no topic called {$unknownWords})" : '').'. Agree one with the student and set it with set_topic (or add the course\'s topics with add_topics), then save again.'
                : "Nothing was saved: every {$words[0]} was empty.";
        }

        $reviewed = $this->writeBack->reviewItems($by, $context->sessionId, $items);
        $module = $session->moduleId !== null ? $this->modules->find($by, $session->moduleId)->title : null;
        foreach ($reviewed as &$item) {
            if ($item['topic'] === null) {
                // No topic: under the session's module (the write-back keeps '' as none).
                $item['topic_id'] = '';
                $item['topic_name'] = $module !== null ? "{$module} (no topic)" : 'the course (no module or topic)';
            }
        }
        unset($item);
        $already = count(array_filter($reviewed, fn ($item) => $item['saved']));
        $result = $this->writeBack->apply($by, $context->sessionId, $reviewed, note: false);
        $saved = (int) ($result['saved'][$kind] ?? 0);
        $context->effects->saved($kind, $saved);
        $topics = array_values(array_unique(array_map(fn ($item) => (string) $item['topic_name'], array_filter($reviewed, fn ($item) => ! $item['saved']))));

        return implode(' ', array_filter([
            $saved > 0 ? 'Saved '.$saved.' '.($saved === 1 ? $words[0] : $words[1]).($topics !== [] ? ' in '.implode(', ', $topics) : '').'.' : 'Nothing new was saved.',
            $already > 0 ? $already.' '.($already === 1 ? 'was' : 'were').' saved already in this session.' : null,
            $empty > 0 ? $empty.' '.($empty === 1 ? 'was' : 'were').' empty.' : null,
            $without > 0 ? $without.' could not be saved: this session has no topic yet (set_topic or add_topics first).' : null,
            $unknown !== [] && ($fallback !== null || $loose) ? "The course has no topic called {$unknownWords}, so ".($fallback !== null ? "the session's topic was used" : "they went under the session's module").'; add_topics adds it.' : null,
            $result['failed'] !== [] ? count($result['failed']).' could not be saved: '.implode('; ', array_unique(array_column($result['failed'], 1))).'.' : null,
            $saved > 0 ? 'Tell the student in a line what you saved.' : null,
        ]));
    }

    /**
     * The items the model sent under $key, as a list of their topic and fields.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return list<array{topic: mixed, fields: array<string, mixed>}>
     */
    protected static function items(array $input, string $key, array $fields): array
    {
        $out = [];
        foreach (is_array($input[$key] ?? null) ? $input[$key] : [] as $one) {
            if (is_array($one)) {
                $out[] = ['topic' => $one['topic'] ?? null, 'fields' => array_intersect_key($one, array_flip($fields))];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the schema of a list of items with these string fields and an optional topic */
    protected static function listOf(string $key, array $fields, string $what): array
    {
        $properties = ['topic' => ['type' => 'string', 'description' => 'The course topic it belongs to, by its exact name in the course. Left out: the session\'s topic.']];
        foreach ($fields as $field => $description) {
            $properties[$field] = ['type' => 'string', 'description' => $description];
        }

        return ['type' => 'object', 'properties' => [
            $key => ['type' => 'array', 'description' => $what, 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($fields), 'additionalProperties' => false]],
        ], 'required' => [$key], 'additionalProperties' => false];
    }
}
