<?php

namespace Tests\Acceptance\G2;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G2 over HTTP: every route, web and API, requested as A with B's and C's ids in every id slot. Nothing of theirs may
 * come back, and nothing of theirs may change. A route with an id parameter this suite cannot fill fails the suite,
 * so a new route is never silently skipped.
 */
class HttpSurfaceTest extends TestCase
{
    use World;

    /** Route parameters that are not record ids: a fixed harmless value. */
    private const NOT_IDS = ['component' => 'x', 'filename' => 'x.js', 'format' => 'pdf', 'panel' => 'notes', 'path' => 'x', 'area' => 'study', 'target' => 'claude', 'token' => 'x', 'page' => '1'];

    /** Routes that end A's own session or account: requested last, never with others' ids. */
    private const ENDS_A = ['logout', 'account.delete', 'account.destroy'];

    /** Framework asset and debug routes: no learner data behind them. */
    private static function framework(Route $route): bool
    {
        return str_starts_with($route->uri(), '_') || str_starts_with($route->uri(), 'livewire') || str_starts_with($route->uri(), 'storage/');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @return array<string, list<string>> each id parameter name => the candidate ids of $who */
    private function idsFor(string $who): array
    {
        $ids = $this->ids($who);
        $entry = (string) DB::table('journal_entries')->where('learner_id', $ids['learner'])->value('id');

        return [
            'workspace' => [$ids['course']], 'module' => [$ids['module']], 'folder' => [$ids['folder']], 'note' => [$ids['note']],
            'file' => [$ids['file']], 'topic' => [$ids['topic']], 'session' => [$ids['session']], 'assignment' => [$ids['assignment']],
            'classSession' => [$ids['class']], 'recording' => [(string) Str::uuid()], 'question' => [$ids['question']],
            'plansection' => [$ids['section']], 'section' => [$ids['section']], 'entry' => [$entry],
            // A bare {id}: try it as every kind of record.
            'id' => array_values(array_unique([$entry, ...array_values(array_diff_key($ids, ['learner' => 1, 'user' => 1]))])),
        ];
    }

    /** @return list<string> every URI of $route with $who's ids in its id slots */
    private function uris(Route $route, string $who): array
    {
        $uris = [preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri())];
        $known = $this->idsFor($who);
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
        foreach ($m[1] as $param) {
            $values = $known[$param] ?? (isset(self::NOT_IDS[$param]) ? [self::NOT_IDS[$param]] : null);
            $this->assertNotNull($values, "Route {$route->uri()} has a parameter {{$param}} the G2 suite cannot fill: add it.");
            $next = [];
            foreach ($uris as $uri) {
                foreach ($values as $value) {
                    $next[] = str_replace('{'.$param.'}', $value, $uri);
                }
            }
            $uris = $next;
        }

        return array_map(fn (string $uri) => '/'.ltrim($uri, '/'), $uris);
    }

    public function test_every_route_requested_as_a_with_bs_and_cs_ids_returns_and_changes_nothing_of_theirs(): void
    {
        $before = [$this->footprint('B'), $this->footprint('C')];
        $user = $this->learners['A']['user'];
        $requests = 0;

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_contains($route->uri(), '{') || in_array($route->getName(), self::ENDS_A, true) || self::framework($route)) {
                continue;
            }
            foreach (['B', 'C'] as $other) {
                foreach ($this->uris($route, $other) as $uri) {
                    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                        $this->app['auth']->forgetGuards();
                        $response = $this->actingAs($user)->withSession($this->confirmedSession())
                            ->json($method, $uri, ['text' => 'x', 'title' => 'x', 'name' => 'x'], ['Accept' => 'text/html, application/json']);
                        $requests++;
                        $this->assertNothingOfOthers((string) $response->getContent(), "{$method} {$uri} ({$other}'s ids)");
                        $this->assertNotSame(500, $response->getStatusCode(), "{$method} {$uri} failed with 500 for another learner's ids.");
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $requests, 'the route sweep ran');
        $this->assertSame($before, [$this->footprint('B'), $this->footprint('C')], 'A changed another learner\'s records over HTTP.');
    }

    public function test_every_page_a_can_open_on_a_s_own_records_shows_nothing_of_b_or_c(): void
    {
        $user = $this->learners['A']['user'];
        $pages = 0;
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || in_array($route->getName(), self::ENDS_A, true) || self::framework($route)) {
                continue;
            }
            foreach ($this->uris($route, 'A') as $uri) {
                $this->app['auth']->forgetGuards();
                $response = $this->actingAs($user)->withSession($this->confirmedSession())->get($uri);
                $pages++;
                $this->assertNotSame(500, $response->getStatusCode(), "GET {$uri} failed on A's own records.");
                $this->assertNothingOfOthers((string) $response->getContent(), "GET {$uri} (A's own)");
            }
        }
        $this->assertGreaterThan(60, $pages);
    }
}
