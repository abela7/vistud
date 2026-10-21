<?php

namespace Tests\Acceptance\G2;

use App\Engine\Context\Stack;
use App\Engine\Jobs\ChooseTopics;
use App\Identity\PrincipalFactory;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerCache;
use App\Study\Sessions;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\ConnectsAiApps;
use Tests\TestCase;

/**
 * Testing the test (ADR 0001, G2): with isolation deliberately broken, the suite's own checks must fail. Every class
 * on the data path is final, so each break is made where a real bug would show: in what A's requests are served.
 *
 * - The learner filter removed: B's rows appear in A's stream. Encrypted under B's key, they cannot be read as A,
 *   so the break surfaces as a failed page; the suite counts a failed page as a failure.
 * - Learner scoping turned off when results are fetched again: A's requests resolve to B's stream.
 * - A cache key reused across learners: B's cached text lands where A reads it.
 */
class BrokenBuildsTest extends TestCase
{
    use ConnectsAiApps, World;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** The suite's checks, as A, over pages, MCP and the tutor's context: the same assertions the suite uses. */
    private function sweepAsA(): void
    {
        $user = $this->learners['A']['user'];
        $ids = $this->ids('A');
        foreach ([route('workspaces.show', $ids['course']), route('workspaces.notes.show', [$ids['course'], $ids['note']])] as $url) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($user)->withSession($this->confirmedSession())->get($url);
            $this->assertNotSame(500, $response->getStatusCode(), "{$url} failed");
            $this->assertNothingOfOthers((string) $response->getContent(), $url);
        }
        $this->token ??= $this->connectApp($user, 'read')['access_token'];
        $token = $this->token;
        foreach (['courses', 'notes', 'search_everything', 'memories'] as $tool) {
            $this->assertNothingOfOthers((string) $this->callTool($token, $tool, ['course_id' => $ids['course'], 'query' => $this->learners['B']['concept']])->getContent(), "tool {$tool}");
        }
        $by = app(PrincipalFactory::class)->forUser($user, 'web');
        $this->assertNothingOfOthers(app(Stack::class)->build($by, app(Sessions::class)->find($by, $ids['session']), null, true)->system, 'tutor context');
        $this->assertNothingOfOthers((string) json_encode(ChooseTopics::review($by, $ids['module'], null)), 'topic review cache');
    }

    /** $mustSeeText: the break must be caught by finding B's text, not merely by something failing. */
    private function assertTheSuiteFails(string $break, Closure $breakIt, bool $mustSeeText = true): void
    {
        $this->sweepAsA(); // Intact: the checks pass.
        $breakIt();
        try {
            $this->sweepAsA();
        } catch (AssertionFailedError $e) {
            if ($mustSeeText) {
                $this->assertStringContainsString('private text reached learner A', $e->getMessage(), "{$break} was caught, but not by finding B's text.");
            }
            $this->addToAssertionCount(1);

            return;
        } catch (\Throwable $e) {
            $this->assertFalse($mustSeeText, "{$break} crashed the sweep instead of being seen: ".$e->getMessage());

            return;
        }
        $this->fail("With {$break}, the G2 checks still passed: the suite cannot see this break.");
    }

    public function test_a_removed_learner_filter_is_caught(): void
    {
        $this->assertTheSuiteFails('the learner filter removed', function () {
            $a = $this->ids('A');
            $b = $this->ids('B');
            // What a query without its learner filter returns: B's note in A's module.
            foreach (DB::table('notes')->where('learner_id', $b['learner'])->get() as $row) {
                DB::table('notes')->insert(array_merge((array) $row, ['id' => (string) Str::uuid(), 'learner_id' => $a['learner'], 'workspace_id' => $a['course'], 'module_id' => $a['module']]));
            }
        }, mustSeeText: false); // B's text is encrypted under B's key: the break shows as A's page failing.
    }

    public function test_learner_scoping_turned_off_on_refetch_is_caught(): void
    {
        $this->assertTheSuiteFails('learner scoping turned off', function () {
            $a = $this->ids('A');
            $b = $this->ids('B');
            // A's requests now resolve to B's stream, as a refetch scoped by the row instead of the caller would.
            $parked = $this->student()->id;
            DB::table('learners')->where('user_id', $parked)->delete();
            DB::table('learners')->where('id', $a['learner'])->update(['user_id' => $parked]);
            DB::table('learners')->where('id', $b['learner'])->update(['user_id' => $a['user']]);
            $this->learners['A']['ids'] = array_merge($b, ['user' => $a['user']]);
        });
    }

    public function test_a_cache_key_reused_across_learners_is_caught(): void
    {
        $this->assertTheSuiteFails('a cache key reused across learners', function () {
            $a = $this->ids('A');
            // B's topic review written under the key A reads.
            LearnerCache::put(LearnerScope::forJob($a['learner']), 'topic-review:'.$a['learner'].':module:'.$a['module'], [
                'topics' => [['name' => 'Cached '.$this->marker('B'), 'reason' => $this->marker('B')]],
            ], now()->addHour());
        });
    }
}
