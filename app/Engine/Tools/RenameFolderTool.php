<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\FolderDetails;
use App\Study\Folders;

final class RenameFolderTool implements Tool
{
    public function __construct(private Folders $folders) {}

    public function name(): string
    {
        return 'rename_folder';
    }

    public function description(): string
    {
        return 'Renames an existing folder in the course. Use it when the student asks to rename or change a folder\'s name.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'folder' => ['type' => 'string', 'description' => 'The current name (or part of it) of the folder to rename.'],
            'new_name' => ['type' => 'string', 'description' => 'The new name for the folder.'],
        ], 'required' => ['folder', 'new_name'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = Lookup::text($input, 'folder');
        $newName = Lookup::text($input, 'new_name');
        if ($wanted === null || $wanted === '') {
            return 'Say which folder to rename.';
        }
        if ($newName === null || trim($newName) === '') {
            return 'Send the new name for the folder.';
        }
        $newName = trim($newName);
        $folder = Lookup::one($this->folders->tree($by, $context->workspaceId), $wanted, fn (FolderDetails $f) => $f->name, 'folder');
        if (is_string($folder)) {
            return $folder;
        }
        if (($ask = Agreement::needed($by, $context, "rename_folder:{$folder->id}:".mb_strtolower($newName), "Rename the folder \"{$folder->name}\" to \"{$newName}\"?")) !== null) {
            return $ask;
        }

        $this->folders->rename($by, $folder->id, $newName);

        return "Renamed folder \"{$folder->name}\" to \"{$newName}\".";
    }
}
