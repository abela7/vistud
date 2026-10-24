<?php

namespace Tests\Feature\Mcp;

use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Topics a chat app adds with no study session open land in the module it names, never silently in no module
 * (2026-10-24: ChatGPT's 8 topics for a workshop landed outside the module, and its Topics tab stayed empty).
 */
class AddTopicsFromAppTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_topics_go_in_the_module_of_module_id_and_with_none_named_the_app_is_told_which_modules_there_are(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Database Systems'])->id;
        app(Modules::class)->create($by, $course, ['title' => 'Lecture 01']);
        $workshop = app(Modules::class)->create($by, $course, ['title' => 'Workshop A'])->id;
        $token = $this->connectApp($ada, 'write')['access_token'];

        $said = (string) $this->callTool($token, 'add_topics', [
            'course_id' => $course, 'module_id' => $workshop,
            'topics' => [['name' => 'Second Normal Form (2NF)'], ['name' => 'Third Normal Form (3NF)']],
        ])->assertOk()->json('result.content.0.text');
        $this->assertStringStartsWith('Added 2 topics', $said);
        $this->assertSame([$workshop, $workshop], array_map(fn ($t) => $t->moduleId, app(Topics::class)->list($by, $course)));

        // No module at all: nothing lands outside the modules, and the app learns what to send.
        $said = (string) $this->callTool($token, 'add_topics', ['course_id' => $course, 'topics' => [['name' => 'BCNF']]])
            ->assertOk()->json('result.content.0.text');
        $this->assertStringContainsString('BCNF: say which module it belongs in', $said);
        $this->assertStringContainsString('"Workshop A"', $said);
        $this->assertCount(2, app(Topics::class)->list($by, $course));
    }
}
