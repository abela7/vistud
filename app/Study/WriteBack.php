<?php

namespace App\Study;

use App\Brain\Store\JournalStore;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use Carbon\CarbonImmutable;

/**
 * The end-of-session write-back (docs/specs/study-memory.md §4.4): what the
 * tutor marked in a chat, pasted by the student, saved where it belongs once
 * the student has reviewed it. Nothing is saved without their say (S3):
 *
 * - a finding, a question and a flashcard go to the topic they name;
 * - an answer to a check question becomes evidence: the question is a task
 *   (the same question asked again is the same task), the task exercises
 *   the topic, and the answer is an attempt judged by the AI, in this
 *   session, which is what the mastery rules count;
 * - a proposed status is applied only if the student ticks it;
 * - the summary and the last checkpoint stay on the session, for the next
 *   briefing; and a session note gathers it all in the module.
 *
 * A mark saved once is recognised when the same chat is pasted again.
 */
final class WriteBack
{
    public function __construct(
        private Sessions $sessions,
        private Topics $topics,
        private Findings $findings,
        private Questions $questions,
        private Flashcards $flashcards,
        private Notes $notes,
        private Memory $memory,
        private JournalStore $journal,
    ) {}

    /**
     * The marks in the pasted text, ready to review: each with its topic
     * matched (`topic_id` is a topic's id, or `new` with `topic_name` for one
     * to create), whether it was saved before, and whether it's ticked.
     * Only the last summary and the last checkpoint are kept.
     *
     * @return list<array<string, mixed>>
     */
    public function review(Principal $by, string $sessionId, string $text): array
    {
        $session = $this->sessions->find($by, $sessionId);
        $topics = $this->topics->list($by, $session->workspaceId);
        $names = [];
        foreach ($topics as $topic) {
            $names[mb_strtolower($topic->name)] = $topic;
        }
        $sessionTopic = $session->topicId !== null ? collect($topics)->firstWhere('id', $session->topicId) : null;
        $captured = array_flip($this->sessions->captured($by, $sessionId));

        $items = Capture::parse($text);
        foreach (['summary', 'checkpoint'] as $single) {
            $last = null;
            foreach ($items as $i => $item) {
                if ($item['kind'] === $single) {
                    if ($last !== null) {
                        unset($items[$last]);
                    }
                    $last = $i;
                }
            }
        }

        $reviewed = [];
        foreach (array_values($items) as $item) {
            if (! in_array($item['kind'], ['summary', 'checkpoint'], true)) {
                $match = $item['topic'] !== null ? ($names[mb_strtolower($item['topic'])] ?? null) : $sessionTopic;
                $item['topic_id'] = $match?->id ?? 'new';
                $item['topic_name'] = $match?->name ?? ($item['topic'] ?? 'General');
            }
            $item['saved'] = isset($captured[$item['fingerprint']]);
            // Statuses are the student's decision: never ticked for them.
            $item['include'] = ! $item['saved'] && $item['kind'] !== 'status';
            $reviewed[] = $item;
        }

        return $reviewed;
    }

    /**
     * Saves the ticked items. Returns what was saved, by kind, and the items
     * that couldn't be (with the reason), so the screen can say so.
     *
     * @param  list<array<string, mixed>>  $items  as review() gave them, possibly edited
     * @return array{saved: array<string, int>, failed: list<array{0: int, 1: string}>}
     */
    public function apply(Principal $by, string $sessionId, array $items, bool $note = true): array
    {
        $session = $this->sessions->find($by, $sessionId);
        $scope = Guard::learner($by);
        $topics = [];
        foreach ($this->topics->list($by, $session->workspaceId) as $topic) {
            $topics[$topic->id] = $topic;
        }
        $moduleId = $session->moduleId ?? ($session->topicId !== null ? ($topics[$session->topicId]->moduleId ?? null) : null);
        $at = $session->endedAt ?? Memory::now();
        $quiz = $session->tutoring['quiz'] ?? 'normal';

        $saved = [];
        $failed = [];
        $fingerprints = [];
        $kept = [];
        foreach ($items as $index => $item) {
            if (! is_array($item) || ! ($item['include'] ?? false) || ! in_array($item['kind'] ?? null, Capture::KINDS, true)) {
                continue;
            }
            try {
                $topicId = in_array($item['kind'], ['summary', 'checkpoint'], true) ? null : $this->topicFor($by, $session, $item, $topics, $moduleId);
                $topicName = $topicId !== null ? $topics[$topicId]->name : null;
                match ($item['kind']) {
                    'finding' => $this->findings->add($by, $topicId, ['text' => (string) ($item['text'] ?? '')], 'ai'),
                    'question' => $this->questions->ask($by, $session->workspaceId, (string) ($item['text'] ?? ''), $topicId, $topics[$topicId]->moduleId ?? $moduleId, $session->id),
                    'flashcard' => $this->flashcards->add($by, $session->workspaceId, $topicId, $item['front'] ?? '', $item['back'] ?? '', 'ai', $session->id),
                    'attempt' => $this->attempt($scope, $by, $session, $topicId, $item, $at, $quiz),
                    'status' => $this->topics->report($by, $topicId, (string) ($item['proposed'] ?? '')),
                    'summary' => $this->sessions->setSummary($by, $session->id, (string) ($item['text'] ?? '')),
                    'checkpoint' => $this->sessions->setCheckpoint($by, $session->id, (string) ($item['text'] ?? '')),
                };
            } catch (Unprocessable $e) {
                $fields = $e->details['fields'] ?? [];
                $failed[] = [$index, $fields === [] ? $e->getMessage() : reset($fields)[0]];

                continue;
            } catch (NotFound|Conflict) {
                $failed[] = [$index, 'Its topic or session no longer exists.'];

                continue;
            }
            $saved[$item['kind']] = ($saved[$item['kind']] ?? 0) + 1;
            $fingerprints[] = is_string($item['fingerprint'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $item['fingerprint']) ? $item['fingerprint'] : Capture::fingerprint($item);
            $kept[] = $item + ['topic_label' => $topicName ?? null];
        }

        $noteId = null;
        if ($note && $kept !== []) {
            $noteId = $this->sessionNote($by, $session, $kept, $moduleId, $topics);
        }
        if ($fingerprints !== []) {
            $this->sessions->remember($by, $session->id, $fingerprints, $noteId);
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /** The topic an item goes to: the one chosen, or a new one made with the given name (once per name). */
    private function topicFor(Principal $by, SessionDetails $session, array $item, array &$topics, ?string $moduleId): string
    {
        $chosen = (string) ($item['topic_id'] ?? '');
        if ($chosen !== 'new') {
            return isset($topics[$chosen]) ? $chosen : throw new NotFound;
        }
        $name = trim((string) ($item['topic_name'] ?? ''));
        foreach ($topics as $topic) {
            if (mb_strtolower($topic->name) === mb_strtolower($name)) {
                return $topic->id;
            }
        }
        $created = $this->topics->create($by, $session->workspaceId, $name === '' ? 'General' : $name, $moduleId);
        $topics[$created->id] = $created;

        return $created->id;
    }

    /**
     * An answer to a check question, as evidence: the question as a task
     * (its id comes from its words, so asking it again is the same task), a
     * claim that the task exercises the topic, and the attempt, judged by the
     * AI, in this session.
     */
    private function attempt(LearnerScope $scope, Principal $by, SessionDetails $session, string $topicId, array $item, string $at, string $quiz): void
    {
        $asked = trim((string) ($item['asked'] ?? ''));
        Input::refuse($asked === '' ? ['asked' => 'Say what was asked.'] : (mb_strlen($asked) > Capture::LIMITS['asked'] ? ['asked' => 'That question is too long.'] : []));
        $answer = mb_substr(trim((string) ($item['answer'] ?? '')), 0, Capture::LIMITS['answer']);
        // The tutor's feedback, kept with the answer: what was right, and what to fix.
        $feedback = array_filter(['right' => mb_substr(trim((string) ($item['right'] ?? '')), 0, Capture::LIMITS['right']), 'fix' => mb_substr(trim((string) ($item['fix'] ?? '')), 0, Capture::LIMITS['fix'])], fn (string $text) => $text !== '');
        $normalised = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $asked)));
        $taskId = 'chat-'.substr(sha1($session->workspaceId.'|'.$normalised), 0, 40);
        $result = in_array($item['result'] ?? null, Capture::RESULTS, true) ? $item['result'] : 'unjudged';
        $form = in_array($item['form'] ?? null, Capture::FORMS, true) ? $item['form'] : ($quiz === 'easy' ? 'recall' : 'apply');
        $link = fn (array $spec) => $spec + ['session' => $session->id];

        $specs = [];
        if ($this->journal->existingEntities($scope, [['task', $taskId]]) === []) {
            $specs[] = $link([
                'id' => Ids::new(), 'kind' => 'record', 'actor' => Memory::actor($scope, $by), 'occurred_at' => $at,
                'body' => ['record_type' => 'task', 'record_id' => $taskId, 'key' => 'chat/'.substr($taskId, 5), 'title' => mb_substr($asked, 0, 120), 'status' => 'active'],
                'content' => ['prompt' => $asked],
            ]);
        }
        if (! $this->exercises($scope, $taskId, $topicId)) {
            $specs[] = $link(Memory::claim($scope, $by, 'relates', ["task:{$taskId}", "topic:{$topicId}"], ['relation' => 'exercises', 'status' => 'active'], [], $at));
        }
        $specs[] = $link(Memory::observation($scope, $by, 'attempt', [
            'task' => $taskId, 'form' => $form, 'support' => ($item['support'] ?? null) === 'hinted' ? 'hinted' : 'unaided',
            'setting' => 'chat', 'outcome' => $result, 'judged_by' => 'ai',
        ], [], array_filter(['answer' => $answer], fn (string $text) => $text !== '') + $feedback, $at));

        $this->memory->append($scope, $specs);
    }

    /** Whether a claim already says the task exercises the topic. */
    private function exercises(LearnerScope $scope, string $taskId, string $topicId): bool
    {
        $claims = LearnerTables::query($scope, 'journal_refs')->where('role', 'target')->where('ref_type', 'task')->where('ref_id', $taskId)->pluck('entry_id')->all();

        return $claims !== [] && LearnerTables::query($scope, 'journal_refs')->whereIn('entry_id', $claims)
            ->where('role', 'target')->where('ref_type', 'topic')->where('ref_id', $topicId)->exists();
    }

    /**
     * The session note: what was saved, readable in the module. The first
     * write-back makes it; later ones add to it.
     */
    private function sessionNote(Principal $by, SessionDetails $session, array $kept, ?string $moduleId, array $topics): ?string
    {
        $zone = $this->sessions->timezone($by);
        $when = CarbonImmutable::parse($session->startedAt)->setTimezone($zone);
        $topicName = $session->topicId !== null ? ($topics[$session->topicId]->name ?? null) : null;
        $blocks = $this->noteBlocks($kept);

        $existing = $this->sessions->noteId($by, $session->id);
        if ($existing !== null) {
            try {
                $note = $this->notes->open($by, $existing);
                if ($note->trashedAt === null) {
                    $doc = $note->doc ?? NoteDoc::empty();
                    $added = [self::heading('Added '.CarbonImmutable::now($zone)->format('D j M, H:i'), 2), ...$blocks];
                    $this->saveNote($by, $note->id, $note->version, $note->title, ['type' => 'doc', 'content' => [...($doc['content'] ?? []), ...$added]]);

                    return $note->id;
                }
            } catch (NotFound) {
                // Deleted since: a new one is made below.
            }
        }

        $title = mb_substr('Session: '.($topicName ?? 'study').' · '.$when->format('D j M'), 0, 200);
        $note = $moduleId !== null
            ? $this->notes->create($by, 'module', $moduleId, $title)
            : $this->notes->create($by, 'workspace', $session->workspaceId, $title);
        $intro = [self::paragraph('From a study session on '.$when->format('D j M Y, H:i').'. What the tutor marked and you saved.')];
        $this->saveNote($by, $note->id, $note->version, $title, ['type' => 'doc', 'content' => [...$intro, ...$blocks]]);

        return $note->id;
    }

    private function saveNote(Principal $by, string $noteId, int $version, string $title, array $doc): void
    {
        $this->notes->save($by, $noteId, [
            'base_version' => $version, 'save_id' => 'writeback-'.substr(Ids::new(), -24), 'client_id' => 'vistud-writeback',
            'title' => $title, 'doc' => $doc,
        ]);
    }

    /** @return list<array<string, mixed>> the note's blocks, a section per kind */
    private function noteBlocks(array $kept): array
    {
        $of = fn (string $kind) => array_values(array_filter($kept, fn ($i) => $i['kind'] === $kind));
        $topic = fn (array $i) => $i['topic_label'] !== null ? " ({$i['topic_label']})" : '';
        $result = ['correct' => 'Right', 'partial' => 'Partly right', 'incorrect' => 'Not yet', 'unjudged' => 'Not marked'];
        $blocks = [];
        $section = function (string $title, array $lines) use (&$blocks) {
            if ($lines !== []) {
                $blocks[] = self::heading($title, 3);
                $blocks[] = ['type' => 'bulletList', 'content' => array_map(fn ($line) => ['type' => 'listItem', 'content' => [self::paragraph($line)]], $lines)];
            }
        };

        foreach ($of('summary') as $summary) {
            $blocks[] = self::heading('Summary', 3);
            $blocks[] = self::paragraph($summary['text']);
        }
        $section('Key points', array_map(fn ($i) => $i['text'].$topic($i), $of('finding')));
        $section('Questions', array_map(fn ($i) => $i['text'].$topic($i), $of('question')));
        $section('Flashcards', array_map(fn ($i) => $i['front'].' → '.$i['back'], $of('flashcard')));
        $section('Answers', array_map(fn ($i) => ($result[$i['result']] ?? 'Not marked').': '.$i['asked'].($i['answer'] !== '' ? ' Answer: '.$i['answer'] : '').(($i['right'] ?? '') !== '' ? ' Right: '.$i['right'] : '').(($i['fix'] ?? '') !== '' ? ' To fix: '.$i['fix'] : ''), $of('attempt')));
        $section('Statuses', array_map(fn ($i) => "{$i['topic_label']}: {$i['proposed']}", $of('status')));
        foreach ($of('checkpoint') as $checkpoint) {
            $blocks[] = self::heading('Where it stands', 3);
            $blocks[] = self::paragraph($checkpoint['text']);
        }

        return $blocks;
    }

    private static function heading(string $text, int $level): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function paragraph(string $text): array
    {
        return $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
