<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Workspaces;

final class RenameCourseTool implements Tool
{
    public function __construct(private Workspaces $workspaces) {}

    public function name(): string
    {
        return 'rename_course';
    }

    public function description(): string
    {
        return 'Renames the course (workspace). Use it when the student asks to rename or change the course\'s name.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'new_name' => ['type' => 'string', 'description' => 'The new name for the course.'],
        ], 'required' => ['new_name'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $newName = Lookup::text($input, 'new_name');
        if ($newName === null || trim($newName) === '') {
            return 'Send the new name for the course.';
        }
        $newName = trim($newName);
        if (($ask = Agreement::needed($by, $context, "rename_course:{$context->workspaceId}:".mb_strtolower($newName), "Rename the course to \"{$newName}\"?")) !== null) {
            return $ask;
        }
        $updated = $this->workspaces->update($by, $context->workspaceId, ['name' => $newName]);

        return "Renamed course to \"{$updated->name}\".";
    }
}
