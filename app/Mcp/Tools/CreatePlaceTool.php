<?php

namespace App\Mcp\Tools;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\ModuleParts;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Container\Container;

/**
 * Makes a course's shape from a chat app (the owner's ask, 2026-10-24: ChatGPT could read his Canvas module list but
 * not make the modules). `create_module` makes a module, with its folders when given; `create_folder` makes a folder
 * in a module or in another folder. A module or folder of the same name already there is given back, never made
 * twice, so asking again is safe. Nothing is deleted or renamed here (renames are confirmed by the student).
 */
final class CreatePlaceTool extends McpTool
{
    public const NAMES = ['create_module', 'create_folder'];

    /** Folders made with a module in one call, at most. */
    public const MAX_FOLDERS = 12;

    public function __construct(private string $what) {}

    protected function info(): string
    {
        return $this->what;
    }

    public function description(): string
    {
        return $this->what === 'create_module'
            ? 'Makes a module in a course (a week, a lecture, a unit), at the end of its modules, with its folders when given (for example Lecture, Lab, Solutions). A module of that title already there is given back, not made twice. Pass course_id from courses. Then the student uploads its files in ViStud.'
            : 'Makes a folder in a module (module_id from course_overview), or inside another folder (folder_id, as the files tool gives it), with a kind when it is one: '.implode(', ', array_keys(ModuleParts::KINDS)).'. A folder of that name already there is given back, not made twice.';
    }

    protected function fields(): array
    {
        $kind = ['type' => 'string', 'enum' => array_keys(ModuleParts::KINDS), 'description' => 'What kind of part it is, when it is one.'];

        return $this->what === 'create_module'
            ? [
                'course_id' => ['type' => 'string', 'description' => 'The course\'s id, from the courses tool.'],
                'title' => ['type' => 'string', 'description' => 'The module\'s title, as the course names it, at most '.Modules::MAX_TITLE.' characters.'],
                'about' => ['type' => 'string', 'description' => 'Optional. What the module covers, in a line or two.'],
                'starts_on' => ['type' => 'string', 'description' => 'Optional. Its first day, YYYY-MM-DD.'],
                'ends_on' => ['type' => 'string', 'description' => 'Optional. Its last day, YYYY-MM-DD.'],
                'folders' => ['type' => 'array', 'maxItems' => self::MAX_FOLDERS, 'description' => 'Optional. Folders to make inside it.', 'items' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The folder\'s name.'],
                    'kind' => $kind,
                ], 'required' => ['name'], 'additionalProperties' => false]],
            ]
            : [
                'folder_id' => ['type' => 'string', 'description' => 'Optional. A folder\'s id, as the files tool gives it, to make this one inside it.'],
                'name' => ['type' => 'string', 'description' => 'The folder\'s name.'],
                'kind' => $kind,
            ];
    }

    protected function requiredFields(): array
    {
        return $this->what === 'create_module' ? ['course_id', 'title'] : ['course_id', 'name'];
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $container = Container::getInstance();
        $course = $container->make(Workspaces::class)->find($by, self::text($arguments, 'course_id') ?? '');

        return $this->what === 'create_module' ? $this->module($by, $course->id, $arguments) : $this->folder($by, $course->id, $arguments);
    }

    /** @param  array<string, mixed>  $arguments */
    private function module(Principal $by, string $courseId, array $arguments): array
    {
        $container = Container::getInstance();
        $modules = $container->make(Modules::class);
        $title = self::text($arguments, 'title') ?? '';
        $there = collect($modules->list($by, $courseId))->first(fn ($m) => mb_strtolower(trim($m->title)) === mb_strtolower($title));
        $module = $there ?? $modules->create($by, $courseId, array_filter([
            'title' => $title,
            'about' => self::text($arguments, 'about'),
            'starts_on' => self::text($arguments, 'starts_on'),
            'ends_on' => self::text($arguments, 'ends_on'),
        ], fn ($v) => $v !== null));

        $made = [];
        $folders = array_slice(array_values(array_filter((array) ($arguments['folders'] ?? []), 'is_array')), 0, self::MAX_FOLDERS);
        foreach ($folders as $folder) {
            $made[] = $this->place($by, $courseId, 'module', $module->id, $folder)['name'];
        }

        $text = ($there !== null ? "The module \"{$module->title}\" was already there" : "Made the module \"{$module->title}\"")
            ." (module_id {$module->id})".($made !== [] ? ', with the folders '.implode(', ', $made) : '').'. The student can upload its files there in ViStud.';

        return [
            'text' => $text,
            'structured' => ['module_id' => $module->id, 'already_there' => $there !== null],
            'type' => 'module',
            'id' => $module->id,
            'changed' => $there === null || $made !== [],
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    private function folder(Principal $by, string $courseId, array $arguments): array
    {
        $container = Container::getInstance();
        $parent = self::text($arguments, 'folder_id');
        $moduleId = self::text($arguments, 'module_id');
        if ($parent !== null) {
            if ($container->make(Folders::class)->find($by, $parent)->workspaceId !== $courseId) {
                throw new NotFound;
            }
            [$type, $id] = ['folder', $parent];
        } elseif ($moduleId !== null) {
            if ($container->make(Modules::class)->find($by, $moduleId)->workspaceId !== $courseId) {
                throw new NotFound;
            }
            [$type, $id] = ['module', $moduleId];
        } else {
            throw new Unprocessable('place_required', 'missing: module_id (from course_overview) or folder_id (as the files tool gives it): where the folder goes.');
        }
        $made = $this->place($by, $courseId, $type, $id, $arguments);

        return [
            'text' => ($made['there'] ? "The folder \"{$made['name']}\" was already there" : "Made the folder \"{$made['name']}\"")." (folder_id {$made['id']}).",
            'structured' => ['folder_id' => $made['id'], 'already_there' => $made['there']],
            'type' => 'folder',
            'id' => $made['id'],
            'changed' => ! $made['there'],
        ];
    }

    /**
     * A folder of this name in the place, or a new one.
     *
     * @param  array<string, mixed>  $input
     * @return array{id: string, name: string, there: bool}
     */
    private function place(Principal $by, string $courseId, string $type, string $id, array $input): array
    {
        $folders = Container::getInstance()->make(Folders::class);
        $name = self::text($input, 'name') ?? '';
        $kind = self::text($input, 'kind');
        $there = collect($folders->tree($by, $courseId))->first(fn (FolderDetails $f) => ($type === 'folder' ? $f->parentId === $id : $f->moduleId === $id && $f->parentId === null)
            && mb_strtolower(trim($f->name)) === mb_strtolower($name));
        $folder = $there ?? $folders->create($by, $type, $id, $name, $kind);

        return ['id' => $folder->id, 'name' => $folder->name, 'there' => $there !== null];
    }

    /** @param  array<string, mixed>  $input */
    private static function text(array $input, string $key): ?string
    {
        return is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
    }
}
