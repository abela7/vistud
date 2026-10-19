<?php

namespace App\Mcp\Resources;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\FileText;
use App\Study\FileTexts;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Support\UriTemplate;

/** One page (or slide, or part) of one of the student's files, by the file's id and the page number. */
#[Name('file_page')]
#[Title('A page of a file')]
#[Description('One page, slide or part of one of the student\'s files, as text.')]
#[Cacheable]
final class FilePage extends McpResource
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('vistud://files/{file}/pages/{page}');
    }

    protected function kind(): string
    {
        return 'file';
    }

    protected function read(Principal $by, array $variables, ?object $connection): array
    {
        $text = Container::getInstance()->make(FileTexts::class)->of($by, $variables['file']);
        $text->file->trashedAt === null || throw new NotFound;
        if ($text->state !== FileText::READY) {
            return ['text' => "\"{$text->file->fileName()}\" cannot be read as text right now (it is a picture, still being prepared, or not readable here).", 'id' => $text->file->id];
        }
        $number = (int) $variables['page'];
        ($number >= 1 && $number <= $text->count()) || throw new NotFound;
        $page = trim($text->pages[$number - 1]);

        return ['text' => $text->label($number)."\n\n".($page !== '' ? $page : '(no text on this '.$text->unit.')'), 'id' => $text->file->id];
    }
}
