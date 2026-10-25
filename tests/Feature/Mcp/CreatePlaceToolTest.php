<?php

namespace Tests\Feature\Mcp;

use App\Study\Folders;
use App\Study\Modules;
use App\Study\Workspaces;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A chat app makes a course's modules and folders (the owner's ask, 2026-10-24: ChatGPT read his Canvas module list
 * but could not make the modules). Asking again makes nothing twice; another student's course is not found.
 */
class CreatePlaceToolTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_a_module_is_made_with_its_folders_once_and_a_folder_goes_in_a_module_or_a_folder(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Database Systems'])->id;
        $token = $this->connectApp($ada, 'write')['access_token'];

        $made = $this->callTool($token, 'create_module', [
            'course_id' => $course, 'title' => 'DBSyS_Lecture_03 : DML (for SELECT)', 'about' => 'SELECT queries.',
            'folders' => [['name' => 'Lecture', 'kind' => 'lecture'], ['name' => 'Lab', 'kind' => 'lab']],
        ])->assertOk();
        $made->assertJsonPath('result.structuredContent.status', 'recorded');
        $moduleId = (string) $made->json('result.structuredContent.module_id');
        $module = app(Modules::class)->find($by, $moduleId);
        $this->assertSame(['DBSyS_Lecture_03 : DML (for SELECT)', 'SELECT queries.'], [$module->title, $module->about]);
        $folders = collect(app(Folders::class)->tree($by, $course));
        $this->assertSame([['Lecture', 'lecture'], ['Lab', 'lab']], $folders->map(fn ($f) => [$f->name, $f->kind])->all());

        // Asked again: the same module, no second one, no second folders.
        $again = $this->callTool($token, 'create_module', ['course_id' => $course, 'title' => 'dbsys_lecture_03 : dml (for select)', 'folders' => [['name' => 'Lab']]])->assertOk();
        $this->assertSame($moduleId, $again->json('result.structuredContent.module_id'));
        $this->assertStringContainsString('was already there', (string) $again->json('result.content.0.text'));
        $this->assertCount(1, app(Modules::class)->list($by, $course));
        $this->assertCount(2, app(Folders::class)->tree($by, $course));

        // A folder in the module, then one inside it.
        $solutions = (string) $this->callTool($token, 'create_folder', ['course_id' => $course, 'module_id' => $moduleId, 'name' => 'Solutions', 'kind' => 'practice'])
            ->assertOk()->json('result.structuredContent.folder_id');
        $inside = $this->callTool($token, 'create_folder', ['course_id' => $course, 'folder_id' => $solutions, 'name' => 'Week 3'])->assertOk();
        $this->assertSame($solutions, app(Folders::class)->find($by, (string) $inside->json('result.structuredContent.folder_id'))->parentId);

        // Nowhere to put it: the app is told what to pass.
        $this->assertStringContainsString('missing: module_id', (string) $this->callTool($token, 'create_folder', ['course_id' => $course, 'name' => 'Loose'])->json('result.content.0.text'));

        // Another student's course is not found.
        $other = app(Workspaces::class)->create($this->principal($this->student()), ['name' => 'Theirs'])->id;
        $this->assertTrue((bool) $this->callTool($token, 'create_module', ['course_id' => $other, 'title' => 'Sneaky'])->json('result.isError'));
    }
}
