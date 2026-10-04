<?php

namespace App\Livewire\Workspaces;

use Livewire\Component;

/**
 * Sends a piece of the chat to the browser while the tutor is still answering (Livewire's `wire:stream`): the
 * answer so far into the element marked `wire:stream.replace="answer"`, and what the tutor is doing into
 * `status`. Nothing is sent from the console (tests, commands): they read the finished chat instead. Tests
 * bind their own to see what was sent.
 */
class ChatStream
{
    public function push(Component $component, string $target, string $html): void
    {
        if (app()->runningInConsole()) {
            return;
        }
        $component->stream(content: $html, replace: true, name: $target);
    }
}
