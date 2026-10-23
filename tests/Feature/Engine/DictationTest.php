<?php

namespace Tests\Feature\Engine;

use App\Engine\Dictation;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Model;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Engine\Usage;
use App\Livewire\Study\EngineSettings;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Voice typing on any device: a recording in, its words out, with the model the student chose (2026-10-23). */
class DictationTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Engine::class, new Fake);
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => ' Selam, how are you? ', 'usage' => ['cost' => 0.0002, 'seconds' => 4]])]);
    }

    public function test_a_recording_is_written_down_with_whisper_unless_they_chose_gemini(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);

        $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'not really audio')])
            ->assertOk()->assertExactJson(['text' => 'Selam, how are you?']);
        Http::assertSent(fn (Sent $request) => str_ends_with($request->url(), '/audio/transcriptions')
            && collect($request->data())->firstWhere('name', 'model')['contents'] === Dictation::WHISPER);

        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'dictation_model' => Dictation::GEMINI]);
        $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.mp4', 'not really audio')])->assertOk();
        Http::assertSent(fn (Sent $request) => collect($request->data())->firstWhere('name', 'model')['contents'] === Dictation::GEMINI);
        // An unknown model is not kept: the choice stays.
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'dictation_model' => 'someone/else']);
        $this->assertSame(Dictation::GEMINI, app(Settings::class)->get($by)->dictationModel);
        // What it cost is counted with the month's AI use.
        $this->assertSame(400, app(Usage::class)->month($by)['total']);
    }

    public function test_the_shared_key_writes_it_down_within_the_days_limit(): void
    {
        config(['vistud.engine.key' => 'sk-or-admin-'.bin2hex(random_bytes(8))]);
        $this->app->instance(Engine::class, new Fake([new Model('fake/quick', 'Fake quick', 0.1, 0.4, 32_000, true, false, false)]));
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => true, 'key' => 'sk-or-students-'.bin2hex(random_bytes(8)), 'models' => 'fake/quick', 'daily' => '0.10']);
        $user = $this->student();
        $by = $this->principal($user);
        app(Settings::class)->consent($by);

        $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertOk();
        LearnerTables::query(LearnerScope::of($by), 'engine_jobs')->update(['cost_micros' => 100_000]);
        $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])
            ->assertStatus(422)->assertJsonPath('error.code', 'engine_daily');
    }

    public function test_it_is_refused_without_consent_without_an_openrouter_key_or_with_no_recording(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        $send = fn (array $data) => $this->actingAs($user)->post(route('dictation'), $data);

        $send(['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertStatus(422)->assertJsonPath('error.code', 'engine_consent');
        app(Settings::class)->consent($by);
        $send([])->assertStatus(422)->assertJsonPath('error.code', 'audio');
        $send(['audio' => UploadedFile::fake()->createWithContent('virus.exe', 'MZ')])->assertStatus(422);
        LearnerTables::query(LearnerScope::of($by), 'engine_settings')->update(['key_provider' => 'anthropic']);
        $send(['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertStatus(422)->assertJsonPath('error.code', 'dictation_key');
        Http::assertNothingSent();
    }

    public function test_the_ai_settings_page_saves_the_voice_typing_model(): void
    {
        $this->withoutVite();
        $user = $this->student();
        $this->actingAs($user);
        Livewire::test(EngineSettings::class)
            ->assertSee('Voice typing')->assertSee('Whisper Large V3 Turbo')->assertSee('Gemini 3.5 Transcribe')
            ->assertSet('dictationModel', Dictation::WHISPER)
            ->set('dictationModel', Dictation::GEMINI)->call('save')->assertHasNoErrors();
        $this->assertSame(Dictation::GEMINI, app(Settings::class)->get($this->principal($user))->dictationModel);
    }
}
