<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ToolInfo;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Two students, one worker (docs/specs/mcp-connections-plan.md M6.7). B's ids answer like ids that never existed.
 * Ten thousand further lookups go through the same services the tools call. A read with no learner filter, and a
 * cache key with no learner, are the breaks the suite is here to catch.
 */
class McpIsolationSuiteTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private const CANARY = 'CANARY-m67-isolation-grace';

    public function test_every_tool_resource_and_prompt_hides_the_other_student(): void
    {
        $ada = $this->student();
        $adaBy = $this->principal($ada);
        $adaCourse = app(Workspaces::class)->create($adaBy, ['name' => 'Ada course'])->id;
        $token = $this->connectApp($ada, 'full')['access_token'];

        $grace = $this->student();
        $graceBy = $this->principal($grace);
        $graceCourse = app(Workspaces::class)->create($graceBy, ['name' => 'Grace '.self::CANARY])->id;
        $module = app(Modules::class)->create($graceBy, $graceCourse, ['title' => 'Module '.self::CANARY])->id;
        $topic = app(Topics::class)->create($graceBy, $graceCourse, 'Topic '.self::CANARY, $module)->id;
        $note = app(Notes::class)->create($graceBy, 'module', $module, 'Note '.self::CANARY);
        app(Questions::class)->ask($graceBy, $graceCourse, 'Question '.self::CANARY, $topic, $module);

        $never = (string) Str::uuid();
        foreach (array_keys(ToolInfo::TOOLS) as $name) {
            $args = ['course_id' => $graceCourse, 'session_id' => $never, 'module_id' => $module, 'topic_id' => $topic, 'note_id' => $note->id, 'assignment_id' => $never, 'card_id' => $never, 'question_id' => $never, 'memory_id' => $never, 'folder_id' => $never];
            $body = (string) $this->callTool($token, $name, $args)->getContent();
            $this->assertStringNotContainsString(self::CANARY, $body, $name);
        }

        foreach ([
            "vistud://courses/{$graceCourse}/brief",
            "vistud://notes/{$note->id}",
            "vistud://files/{$never}/pages/1",
        ] as $uri) {
            $body = (string) $this->mcp($token, 'resources/read', ['uri' => $uri])->getContent();
            $this->assertStringNotContainsString(self::CANARY, $body, $uri);
        }

        foreach (['start_study_session', 'quiz_me', 'review_topic', 'weekly_brief'] as $prompt) {
            $body = (string) $this->mcp($token, 'prompts/get', ['name' => $prompt, 'arguments' => ['topic' => 'joins', 'course' => 'Ada course']])->getContent();
            $this->assertStringNotContainsString(self::CANARY, $body, $prompt);
        }

        // Interleaved: Grace still sees her own course.
        $graceToken = $this->connectApp($grace, 'read')['access_token'];
        $this->assertStringContainsString(self::CANARY, (string) $this->callTool($graceToken, 'courses')->getContent());

        $this->assertSame(0, Artisan::call('vistud:db:orphans'));

        $this->tenThousand($adaBy, $graceCourse, $topic, $note->id);
    }

    public function test_a_removed_filter_an_unscoped_read_and_a_shared_cache_key_are_caught(): void
    {
        $grace = $this->principal($this->student());
        $course = app(Workspaces::class)->create($grace, ['name' => 'Grace'])->id;
        $module = app(Modules::class)->create($grace, $course, ['title' => 'Week'])->id;
        $note = app(Notes::class)->create($grace, 'module', $module, 'Note '.self::CANARY);

        $raw = (string) DB::table('notes')->where('id', $note->id)->value('title');
        $this->assertStringNotContainsString(self::CANARY, $raw);
        // A deliberately broken reader uses the other learner's authorized scope.
        // Merely removing the SQL filter no longer reveals encrypted text.
        $unscoped = (string) LearnerTables::query(LearnerScope::of($grace), 'notes')->where('id', $note->id)->value('title');
        $this->assertStringContainsString(self::CANARY, $unscoped);

        $cache = ['note:'.$note->id => $unscoped];
        $this->assertStringContainsString(self::CANARY, $cache['note:'.$note->id]);
        $scopedKey = 'learner:'.$grace->learnerId.':note:'.$note->id;
        $this->assertArrayNotHasKey($scopedKey, $cache);

        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Mcp'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            if (str_contains($text, 'Cache::')) {
                $offenders[] = $relative.' uses a cache';
            }
            if (str_contains($text, 'DB::') && ! in_array($relative, ['app/Mcp/Connections.php', 'app/Mcp/Confirmations.php', 'app/Mcp/G1b.php'], true)) {
                $offenders[] = $relative.' queries the database';
            }
        }
        $this->assertSame([], $offenders);
    }

    /** Ten thousand lookups as Ada, at Grace's ids and at ids that never existed. */
    private function tenThousand(Principal $ada, string $graceCourse, string $graceTopic, string $graceNote): void
    {
        $never = (string) Str::uuid();
        $topics = app(Topics::class);
        $notes = app(Notes::class);
        $courses = app(Workspaces::class);
        for ($i = 0; $i < 10000; $i++) {
            $which = $i % 3;
            try {
                match ($which) {
                    0 => $courses->find($ada, $i % 2 === 0 ? $graceCourse : $never),
                    1 => $topics->find($ada, $i % 2 === 0 ? $graceTopic : $never),
                    default => $notes->find($ada, $i % 2 === 0 ? $graceNote : $never),
                };
                $this->fail("Lookup {$i} returned another student's row.");
            } catch (NotFound) {
                // The same answer as an id that never existed.
            }
        }
    }
}
