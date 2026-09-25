<?php

namespace App\Brain\Projection;

use App\Brain\Journal\JournalEntry;

/**
 * Derives learner state from journal entries. Deterministic: the same entries
 * and options always give the same snapshot, and no LLM is ever called.
 */
final class Projector
{
    public function __construct(private Rules $rules = new Rules) {}

    /** @param list<JournalEntry> $entries */
    public function project(array $entries, ProjectionOptions $options): array
    {
        $snapshot = $this->run($entries, $options);

        // A label is flagged when it would be lower with only human-checked
        // outcomes, or with every dispute ignored (ADR 0002 §7 flags).
        $humanOnly = $this->run($entries, $options->with(humanJudgedOnly: true));
        $noDisputes = $this->run($entries, $options->with(ignoreDisputes: true));

        foreach ($snapshot['topics'] as $id => $topic) {
            $rank = Derivation::LABELS[$topic['label']];
            if ($rank > Derivation::LABELS[$humanOnly['topics'][$id]['label'] ?? 'not_started']) {
                $snapshot['topics'][$id]['flags'][] = 'includes_ai_judged';
            }
            if ($rank > Derivation::LABELS[$noDisputes['topics'][$id]['label'] ?? 'not_started']) {
                $snapshot['topics'][$id]['flags'][] = 'rests_on_dispute';
            }
            sort($snapshot['topics'][$id]['flags']);
        }

        return $snapshot;
    }

    private function run(array $entries, ProjectionOptions $options): array
    {
        $replay = new Replay($entries, $options, $this->rules);
        $derivation = new Derivation($replay, $this->rules, $options->now);
        $topics = $derivation->topics();
        ksort($topics);

        return [
            'rules' => Rules::VERSION,
            'position' => $replay->maxPosition(),
            'topics' => $topics,
            'misconceptions' => $derivation->misconceptions(),
            'questions' => $derivation->questions(),
            'profile' => $derivation->profile(),
            'attempts' => $derivation->attempts(),
            'rejected_pairs' => $replay->rejectedPairs,
        ];
    }
}
