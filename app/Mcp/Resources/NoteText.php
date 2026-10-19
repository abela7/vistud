<?php

namespace App\Mcp\Resources;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\NoteDoc;
use App\Study\Notes;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Support\UriTemplate;

/** One of the student's notes, by its id, as plain text. */
#[Name('note')]
#[Title('A note')]
#[Description('The text of one of the student\'s notes.')]
#[Cacheable]
final class NoteText extends McpResource
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('vistud://notes/{note}');
    }

    protected function kind(): string
    {
        return 'note';
    }

    protected function read(Principal $by, array $variables, ?object $connection): array
    {
        $note = Container::getInstance()->make(Notes::class)->open($by, $variables['note']);
        $note->trashedAt === null || throw new NotFound;

        return ['text' => $note->displayTitle()."\n\n".NoteDoc::text($note->doc ?? NoteDoc::empty()), 'id' => $note->id];
    }
}
