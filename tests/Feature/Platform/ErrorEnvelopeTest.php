<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Http\Middleware\AddAccountHeader;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    use RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'api.v1'])->prefix('api/v1/_test')->group(function () {
            Route::get('not-found', fn () => throw new NotFound);
            Route::get('gone', fn () => throw new Gone('redacted'));
            Route::get('conflict', fn () => throw new Conflict('version_conflict', 'The note has changed.', ['current_version' => 7]));
            Route::get('confirm', fn () => throw new PasswordConfirmationRequired);
            Route::get('invalid', fn () => throw ValidationException::withMessages(['title' => 'The title is required.']));
            Route::get('crash', fn () => throw new RuntimeException('secret learner text'));
            Route::get('ok', fn () => ['ok' => true]);
            Route::get('private', fn () => ['ok' => true])->middleware('auth');
        });
        Route::middleware('web')->get('_test/web-not-found', fn () => throw new NotFound);
    }

    public function test_app_errors_use_the_envelope_with_the_request_id(): void
    {
        $response = $this->getJson('/api/v1/_test/not-found', ['X-Request-Id' => 'req-12345678']);

        $response->assertStatus(404)
            ->assertHeader('X-Request-Id', 'req-12345678')
            ->assertExactJson(['error' => [
                'code' => 'not_found',
                'message' => 'Not found.',
                'request_id' => 'req-12345678',
            ]]);
    }

    public function test_details_are_included_only_when_present(): void
    {
        $this->getJson('/api/v1/_test/gone')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'gone')
            ->assertJsonPath('error.details.reason', 'redacted');

        $this->getJson('/api/v1/_test/conflict')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'version_conflict')
            ->assertJsonPath('error.details.current_version', 7);

        $this->getJson('/api/v1/_test/confirm')
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'password_confirmation_required')
            ->assertJsonMissingPath('error.details');
    }

    public function test_validation_errors_list_fields(): void
    {
        $this->getJson('/api/v1/_test/invalid')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.fields.title.0', 'The title is required.');
    }

    public function test_unexpected_errors_never_leak_their_message(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/crash');

        $response->assertStatus(500)->assertJsonPath('error.code', 'server_error');
        $this->assertStringNotContainsString('secret learner text', $response->getContent());
    }

    public function test_unknown_api_routes_and_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/_test/nothing-here')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/_test/private')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_request_id_is_generated_when_missing_or_unsafe(): void
    {
        $generated = $this->getJson('/api/v1/_test/ok')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated);

        $replaced = $this->getJson('/api/v1/_test/ok', ['X-Request-Id' => "bad id\n"])->headers->get('X-Request-Id');
        $this->assertNotSame("bad id\n", $replaced);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $replaced);
    }

    public function test_api_responses_name_the_account(): void
    {
        $this->getJson('/api/v1/_test/ok')->assertHeaderMissing(AddAccountHeader::HEADER);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/_test/ok')->assertHeader(AddAccountHeader::HEADER, $user->id);
        $this->actingAs($user)->getJson('/api/v1/_test/not-found')->assertHeader(AddAccountHeader::HEADER, $user->id);
    }

    public function test_browser_pages_get_the_plain_status(): void
    {
        $this->get('/_test/web-not-found')->assertStatus(404);
    }
}
