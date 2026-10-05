<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;

/**
 * What each file of a module is, as the reader summarised it (docs/specs/vistud-2-blueprint.md Appendix B): the summary, how
 * many pages, the topics it covers and its outline, so the tutor knows what a file holds before it opens it with read_file.
 * In a folder's session (Phase 9) it is the folder's files, unless a module is asked for.
 */
final class ModuleFilesTool implements Tool
{
    public function __construct(private Files $files, private FileDigests $digests, private Modules $modules, private Folders $folders) {}

    public function name(): string
    {
        return 'module_files';
    }

    public function description(): string
    {
        return 'The files of a module with what each one is, as the reader summarised it: a short summary, its length, the topics it covers and its outline of headings with pages. Use it to know what a file holds before opening it with read_file, and to see which topics the material covers. Defaults to the session\'s folder when it studies in one, else its module. A file that has not been read yet says so.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it); leave out for the session\'s folder or module.']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $moduleId = $context->moduleId;
        $folderIds = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($this->modules->list($by, $context->workspaceId), $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $moduleId = $module->id;
        } else {
            $folderIds = Lookup::folder($this->folders, $by, $context);
        }
        if ($moduleId === null && $folderIds === null) {
            return 'Say which module: this session has none.';
        }
        $files = array_values(array_filter($this->files->list($by, $context->workspaceId), fn ($file) => $folderIds !== null
            ? $file->folderId !== null && in_array($file->folderId, $folderIds, true)
            : $file->moduleId === $moduleId));
        if ($files === []) {
            return $folderIds !== null ? 'No files in this folder yet. Name the module to see the rest of it.' : 'No files in that module yet.';
        }
        $states = $this->digests->states($by, $files);
        $rows = [];
        foreach ($files as $file) {
            ['digest' => $digest, 'state' => $state] = $states[$file->id];
            $rows[] = $digest?->read() === true
                ? array_filter([
                    'file' => $file->fileName(), 'type' => $file->typeLabel(), 'length' => $digest->pages.' '.($file->kind === 'slides' ? 'slides' : 'pages'),
                    'summary' => $digest->summary, 'topics' => $digest->topics,
                    'outline' => array_map(fn (array $item) => "{$item['page']}: {$item['heading']}", array_slice($digest->outline, 0, 20)),
                    'language' => $digest->language,
                ])
                : ['file' => $file->fileName(), 'type' => $file->typeLabel(), 'read' => false, 'note' => match ($state) {
                    'reading' => 'being read now',
                    'skipped' => $file->kind === 'image' ? 'a picture: the student attaches it in the chat' : 'nothing to read in it',
                    default => 'not read yet: open it with read_file',
                }];
        }

        return Lookup::json($rows);
    }
}
