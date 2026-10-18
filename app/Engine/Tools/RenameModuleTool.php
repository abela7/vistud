<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\ModuleDetails;
use App\Study\Modules;

final class RenameModuleTool implements Tool
{
    public function __construct(private Modules $modules) {}

    public function name(): string
    {
        return 'rename_module';
    }

    public function description(): string
    {
        return 'Renames an existing module in the course. Use it when the student asks to rename or change a module\'s title.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'module' => ['type' => 'string', 'description' => 'The current title (or part of it) of the module to rename. Left out: this session\'s module.'],
            'new_title' => ['type' => 'string', 'description' => 'The new title for the module.'],
        ], 'required' => ['new_title'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $newTitle = Lookup::text($input, 'new_title');
        if ($newTitle === null || trim($newTitle) === '') {
            return 'Send the new title for the module.';
        }
        $newTitle = trim($newTitle);
        $wanted = Lookup::text($input, 'module');
        $module = null;
        if ($wanted !== null) {
            $module = Lookup::forWrite($by, $this->modules->list($by, $context->workspaceId), $wanted, fn (ModuleDetails $m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
        } elseif ($context->moduleId !== null) {
            $module = $this->modules->find($by, $context->moduleId);
        } else {
            return 'Say which module to rename.';
        }
        if (($ask = Agreement::needed($by, $context, "rename_module:{$module->id}:".mb_strtolower($newTitle), "Rename the module \"{$module->title}\" to \"{$newTitle}\"?")) !== null) {
            return $ask;
        }

        $updated = $this->modules->update($by, $module->id, ['title' => $newTitle]);

        return "Renamed module to \"{$updated->title}\".";
    }
}
