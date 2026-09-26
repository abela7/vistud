<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * The JSON API matches docs/api/openapi.json (ADR 0003 §8): every /api/v1
 * route is documented, and responses match their documented schemas.
 */
class OpenApiContractTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_every_api_route_is_documented_and_nothing_undocumented_exists(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => array_map(
                fn (string $method) => $method.' /'.$route->uri(),
                array_diff($route->methods(), ['HEAD']),
            ))
            ->sort()->values()->all();

        $this->assertSame((new OpenApi)->operations(), $routes);
    }

    public function test_me_matches_its_schema(): void
    {
        $spec = new OpenApi;

        $response = $this->actingAs($this->admin())->getJson('/api/v1/me');
        $this->assertSame([], $spec->validateResponse('GET', '/api/v1/me', 200, $response->json()));

        $response = $this->actingAs($this->admin(student: false))->getJson('/api/v1/me');
        $this->assertSame([], $spec->validateResponse('GET', '/api/v1/me', 200, $response->json()));
    }

    public function test_errors_match_the_envelope_schema(): void
    {
        $spec = new OpenApi;
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame([], $spec->validateResponse('GET', '/api/v1/me', 401, $response->json()));
    }

    public function test_the_validator_catches_undocumented_fields(): void
    {
        $problems = (new OpenApi)->validateResponse('GET', '/api/v1/me', 200, [
            'account' => ['id' => 'u', 'name' => 'n', 'email' => 'e', 'roles' => ['student'], 'two_factor_confirmed' => false, 'secret' => 'x'],
            'learner' => null,
            'area' => 'student',
        ]);

        $this->assertSame(['$.account.secret: not documented.'], $problems);
    }
}
