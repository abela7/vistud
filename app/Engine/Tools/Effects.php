<?php

namespace App\Engine\Tools;

/**
 * What a tool did in the course during a chat turn: how many of each thing it saved, the notes it wrote in (with
 * the version it left) and the topic it gave the session, so the chat can say so, link to them, and tell an open
 * note or the session page to show the change.
 */
final class Effects
{
    /** @var array<string, int> flashcard · finding · question · topic => how many */
    private array $saved = [];

    private ?string $topic = null;

    /** @var array<string, array{id: string, title: string, version: int}> */
    private array $notes = [];

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
    public function topic(string $name): void
    {
        $this->topic = $name;
    }

    /**
     * What was done since the last take, and nothing after.
     *
     * @return array{saved?: array<string, int>, notes?: list<array{id: string, title: string, version: int}>, topic?: string}
     */
    public function take(): array
    {
        $taken = array_filter(['saved' => $this->saved, 'notes' => array_values($this->notes), 'topic' => $this->topic]);
        [$this->saved, $this->notes, $this->topic] = [[], [], null];

        return $taken;
    }
}
