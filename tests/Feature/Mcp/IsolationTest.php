<?php

namespace Tests\Feature\Mcp;

use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * One student's AI app can never reach another student's things (docs/specs/mcp-connections-plan.md M6.2, ADR 0003
 * T3): B's ids answer exactly as ids that never existed, and B's canary words never come back.
 */
class IsolationTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private const CANARY = 'CANARY-7f3e9b-grace-only';

    public function test_another_students_ids_answer_like_ids_that_never_existed(): void
    {
        $ada = $this->student();
        $adaToken = $this->connectApp($ada, 'read')['access_token'];
        $adaCourse = app(Workspaces::class)->create($this->principal($ada), ['name' => 'Ada course'])->id;

        $grace = $this->student();
        $by = $this->principal($grace);
        $graceCourse = app(Workspaces::class)->create($by, ['name' => 'Grace '.self::CANARY])->id;
        $graceModule = app(Modules::class)->create($by, $graceCourse, ['title' => 'Module '.self::CANARY])->id;
        app(Topics::class)->create($by, $graceCourse, 'Topic '.self::CANARY, $graceModule);
        $note = app(Notes::class)->create($by, 'module', $graceModule, 'Note '.self::CANARY);
        $graceToken = $this->connectApp($grace, 'read')['access_token'];

        $never = (string) Str::uuid();
        foreach (['course_overview', 'topics', 'questions', 'findings', 'notes', 'files', 'calendar', 'assignments', 'earlier_sessions', 'flashcards_due'] as $tool) {
            $theirs = $this->callTool($adaToken, $tool, ['course_id' => $graceCourse])->assertOk()->json('result');
            $nobodys = $this->callTool($adaToken, $tool, ['course_id' => $never])->assertOk()->json('result');
            $this->assertSame($nobodys, $theirs, "{$tool}: another student's course must look like one that never existed");
            $this->assertTrue($theirs['isError'], $tool);
            $this->assertStringNotContainsString(self::CANARY, json_encode($theirs), $tool);

            // Interleaved: Grace's own token still sees her course.
            $this->callTool($graceToken, $tool, ['course_id' => $graceCourse])->assertOk()->assertJsonPath('result.isError', false);
        }

        // Ada's own course with Grace's module or note id: the same answer as a random id.
        $withTheirModule = $this->callTool($adaToken, 'topics', ['course_id' => $adaCourse, 'module_id' => $graceModule])->json('result');
        $withNoModule = $this->callTool($adaToken, 'topics', ['course_id' => $adaCourse, 'module_id' => $never])->json('result');
        $this->assertSame($withNoModule, $withTheirModule);
        $readTheirs = $this->callTool($adaToken, 'read_note', ['course_id' => $adaCourse, 'note' => $note->id])->json('result');
        $readNobodys = $this->callTool($adaToken, 'read_note', ['course_id' => $adaCourse, 'note' => $never])->json('result');
        // Once the echoed id is replaced by a placeholder, the two answers are the same.
        $this->assertSame(str_replace($never, 'ID', json_encode($readNobodys)), str_replace($note->id, 'ID', json_encode($readTheirs)));

        // Across courses: Grace's canary never shows in Ada's search or course list.
        foreach (['courses', 'search_everything', 'memories', 'current_session'] as $tool) {
            $this->assertStringNotContainsString(self::CANARY, (string) $this->callTool($adaToken, $tool, ['query' => 'Grace'])->getContent(), $tool);
        }

        // The call records say the same for both.
        $outcomes = DB::table('mcp_calls')->where('learner_id', DB::table('learners')->where('user_id', $ada->id)->value('id'))->where('tool', 'topics')->pluck('outcome')->all();
        $this->assertContains('not_found', $outcomes);
    }
}
