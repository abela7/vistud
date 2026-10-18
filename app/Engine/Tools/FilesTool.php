<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Links;
use App\Study\Modules;

/** The files and web links kept in the course: what there is, by module. A file's contents are read with read_file. */
final class FilesTool implements Tool
{
    public function __construct(private Files $files, private Links $links, private Modules $modules, private Folders $folders) {}

    public function name(): string
    {
        return 'files';
    }

    public function description(): string
    {
        return 'The files (slides, PDFs, Word documents, pictures) and web links kept in this course, or in one module: their names, types, sizes and upload dates. Read a file\'s contents, and how long it is (pages, slides, sheets), who made it and when, with read_file.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to.']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $modules = $this->modules->list($by, $context->workspaceId);
        $titles = collect($modules)->pluck('title', 'id');
        $folderNames = [];
        foreach ($this->folders->tree($by, $context->workspaceId) as $f) {
            $folderNames[$f->id] = $f->name;
        }
        $only = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($modules, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $only = $module->id;
        }
        $rows = [];
        foreach ($this->files->list($by, $context->workspaceId) as $file) {
            if ($only === null || $file->moduleId === $only) {
                $rows[] = array_filter(['id' => $file->id, 'file' => $file->fileName(), 'type' => $file->typeLabel(), 'size' => $file->humanSize(), 'uploaded' => substr($file->uploadedAt, 0, 10), 'module' => $file->moduleId !== null ? $titles[$file->moduleId] ?? null : null, 'folder' => $file->folderId !== null ? $folderNames[$file->folderId] ?? null : null]);
            }
        }
        foreach ($this->links->list($by, $context->workspaceId) as $link) {
            if ($only === null || $link->moduleId === $only) {
                $rows[] = array_filter(['id' => $link->id, 'link' => $link->title, 'url' => $link->url, 'module' => $link->moduleId !== null ? $titles[$link->moduleId] ?? null : null, 'folder' => $link->folderId !== null ? $folderNames[$link->folderId] ?? null : null]);
            }
        }

        return $rows === [] ? 'No files or links'.($only !== null ? ' in that module' : '').' yet.' : Lookup::json(array_slice($rows, 0, 80));
    }
}
