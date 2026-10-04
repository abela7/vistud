<?php

namespace App\Engine\Tools;

/**
 * What a tool did in the course during a chat turn: how many of each thing it saved, the notes it wrote in (with
 * the version it left), the topic it gave the session, the statuses it set or proposed and the quizzes it recorded,
 * so the chat can say so, link to them, and tell an open note or the session page to show the change.
 */
final class Effects
{
    /** @var array<string, int> flashcard · finding · question · topic => how many */
    private array $saved = [];

    private ?string $topic = null;

    private ?string $topicId = null;

    /** @var array<string, array{id: string, title: string, version: int}> */
    private array $notes = [];

    /** @var list<array{topic_id: string, topic: string, from: ?string, to: string, applied: bool, reason: ?string, before: ?array{status: ?string, by: ?string, at: ?string}}> */
    private array $statuses = [];

    /** @var list<array{id: string, kind: string, score: int, asked: int}> */
    private array $quizzes = [];

    public function saved(string $kind, int $count): void
    {
        if ($count > 0) {
            $this->saved[$kind] = ($this->saved[$kind] ?? 0) + $count;
        }
    }

    public function wrote(string $noteId, string $title, int $version): void
    {
        $this->notes[$noteId] = ['id' => $noteId, 'title' => $title, 'version' => $version];
    }

    /** The session's topic is now $name. */
    public function topic(string $name, ?string $id = null): void
    {
        [$this->topic, $this->topicId] = [$name, $id];
    }

    /**
     * A topic's status the tutor set (`$applied`, with what it was before, to undo) or proposed for the student to
     * accept (`$applied` false).
     *
     * @param  ?array{status: ?string, by: ?string, at: ?string}  $before
     */
    public function status(string $topicId, string $topic, ?string $from, string $to, bool $applied, ?string $reason = null, ?array $before = null): void
    {
        $this->statuses[] = ['topic_id' => $topicId, 'topic' => $topic, 'from' => $from, 'to' => $to, 'applied' => $applied, 'reason' => $reason, 'before' => $before];
    }

    /** A quiz or test was recorded: `$score` out of 100 over `$asked` questions. */
    public function quiz(string $id, string $kind, int $score, int $asked): void
    {
        $this->quizzes[] = ['id' => $id, 'kind' => $kind, 'score' => $score, 'asked' => $asked];
    }

    /**
     * What was done since the last take, and nothing after.
     *
     * @return array{saved?: array<string, int>, notes?: list<array{id: string, title: string, version: int}>, topic?: string, topic_id?: string, statuses?: list<array<string, mixed>>, quizzes?: list<array<string, mixed>>}
     */
    public function take(): array
    {
        $taken = array_filter(['saved' => $this->saved, 'notes' => array_values($this->notes), 'topic' => $this->topic, 'topic_id' => $this->topicId, 'statuses' => $this->statuses, 'quizzes' => $this->quizzes]);
        [$this->saved, $this->notes, $this->topic, $this->topicId, $this->statuses, $this->quizzes] = [[], [], null, null, [], []];

        return $taken;
    }
}
