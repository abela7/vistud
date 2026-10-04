<?php

namespace App\Engine\Tools;

/**
 * What a tool did in the course during a chat turn: how many of each thing it saved, and the notes it wrote in
 * (with the version it left), so the chat can say so, link to them, and tell an open note to show the change.
 */
final class Effects
{
    /** @var array<string, int> flashcard · finding · question => how many */
    private array $saved = [];

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

    /**
     * What was done since the last take, and nothing after.
     *
     * @return array{saved?: array<string, int>, notes?: list<array{id: string, title: string, version: int}>}
     */
    public function take(): array
    {
        $taken = array_filter(['saved' => $this->saved, 'notes' => array_values($this->notes)]);
        [$this->saved, $this->notes] = [[], []];

        return $taken;
    }
}
