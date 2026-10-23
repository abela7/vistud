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
use App\Livewire\Study\VoiceSettings;
use App\Livewire\Workspaces\TutorChat;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Study\Workspaces;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Voice typing on any device: a recording in, its words out, with the model the student chose (2026-10-23). */
class DictationTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    /** @var list<string> what the service says next, in turn; then the usual answer */
    private array $heard = [];

    /** A status the service fails with, instead of answering. */
    private ?int $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Engine::class, new Fake);
        Http::fake(['*/audio/transcriptions' => fn () => $this->failWith !== null
            ? Http::response(['error' => ['message' => 'Provider returned error']], $this->failWith)
            : Http::response(['text' => array_shift($this->heard) ?? ' Selam, how are you? ', 'usage' => ['cost' => 0.0002, 'seconds' => 4]])]);
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

    public function test_the_language_they_are_taught_in_is_told_to_the_model(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        $language = fn () => collect(Http::recorded()->last()[0]->data())->firstWhere('name', 'language')['contents'] ?? null;
        $say = fn () => $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertOk();

        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => 'Amharic', 'dictation_model' => Dictation::GEMINI]);
        $this->heard = ['ሰላም እንዴት ነህ'];
        $say();
        $this->assertSame('am', $language());
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => 'Afaan Oromo']);
        $say();
        $this->assertSame('om', $language());
        // None chosen, or one the service doesn't know: the model tells which it is.
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => '']);
        $say();
        $this->assertNull($language());
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => 'Klingon']);
        $say();
        $this->assertNull($language());
    }

    public function test_amharic_heard_as_arabic_is_asked_again_of_the_other_model_and_never_put_in_the_box(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        app(Settings::class)->setDictation($by, Dictation::WHISPER, 'am');
        $say = fn () => $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')]);
        $models = fn () => Http::recorded()->map(fn ($pair) => collect($pair[0]->data())->firstWhere('name', 'model')['contents'])->values()->all();

        // Whisper hears Arabic; Gemini, asked again, hears Amharic: the Amharic goes in the box.
        $this->heard = ['مرحبا كيف حالك', 'ሰላም እንዴት ነህ'];
        $say()->assertOk()->assertExactJson(['text' => 'ሰላም እንዴት ነህ']);
        $this->assertSame([Dictation::WHISPER, Dictation::GEMINI], $models());

        // Both hear Arabic: nothing goes in the box, and it says what to do.
        $this->heard = ['مرحبا', 'مرحبا'];
        $say()->assertStatus(422)->assertJsonPath('error.code', 'dictation_language')->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Amharic'));
    }

    public function test_the_language_set_beside_the_microphone_wins_and_detect_it_tells_none(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => 'French']);
        $language = fn () => collect(Http::recorded()->last()[0]->data())->firstWhere('name', 'language')['contents'] ?? null;
        $say = fn () => $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertOk();

        $say();
        $this->assertSame('fr', $language());
        app(Settings::class)->setDictation($by, Dictation::GEMINI, 'en');
        $say();
        $this->assertSame('en', $language());
        app(Settings::class)->setDictation($by, Dictation::GEMINI, 'auto');
        $say();
        $this->assertNull($language());
        $this->assertThrows(fn () => app(Settings::class)->setDictation($by, Dictation::GEMINI, 'xx'), Unprocessable::class);
        $this->assertThrows(fn () => app(Settings::class)->setDictation($by, 'someone/else', 'am'), Unprocessable::class);
    }

    public function test_the_microphones_settings_choose_the_model_and_language(): void
    {
        $this->withoutVite();
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true, 'language' => 'Amharic']);

        Livewire::test(VoiceSettings::class)
            ->assertSee("I'll speak", false)->assertSee('Amharic')->assertSee('Gemini 3.5 Transcribe')
            // Not set beside the microphone yet: "Teach me in"'s language.
            ->assertSet('language', 'am')->assertSet('model', Dictation::WHISPER)
            ->set('model', Dictation::GEMINI)->call('save')->assertHasNoErrors()->assertDispatched('voice-settings-saved');
        $choices = app(Settings::class)->get($by);
        $this->assertSame([Dictation::GEMINI, 'am'], [$choices->dictationModel, $choices->dictationLanguage]);
    }

    public function test_chirp_3_is_offered_with_an_own_key_and_never_used_on_the_shared_key(): void
    {
        $this->withoutVite();
        $model = fn () => collect(Http::recorded()->last()[0]->data())->firstWhere('name', 'model')['contents'];
        $say = fn ($user) => $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'audio')])->assertOk();

        // Their own key: Chirp 3 is offered and used.
        $own = $this->student();
        $by = $this->principal($own);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->actingAs($own);
        Livewire::test(VoiceSettings::class)->assertSee('Google Chirp 3 (best quality, costs more)')
            ->set('model', Dictation::CHIRP)->set('language', 'en')->call('save')->assertHasNoErrors();
        $say($own);
        $this->assertSame(Dictation::CHIRP, $model());

        // The shared key: not offered, refused, and one chosen before is not used (Gemini for Amharic, else Whisper).
        config(['vistud.engine.key' => 'sk-or-admin-'.bin2hex(random_bytes(8))]);
        $this->app->instance(Engine::class, new Fake([new Model('fake/quick', 'Fake quick', 0.1, 0.4, 32_000, true, false, false)]));
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => true, 'key' => 'sk-or-students-'.bin2hex(random_bytes(8)), 'models' => 'fake/quick', 'daily' => '0.10']);
        $student = $this->student();
        $on = $this->principal($student);
        app(Settings::class)->consent($on);
        $this->actingAs($student);
        Livewire::test(VoiceSettings::class)->assertDontSee('Chirp')->set('model', Dictation::CHIRP)->call('save')->assertHasErrors(['model']);
        $this->assertThrows(fn () => app(Settings::class)->setDictation($on, Dictation::CHIRP, 'en'), Unprocessable::class);
        LearnerTables::query(LearnerScope::of($on), 'engine_settings')->update(['dictation_model' => Dictation::CHIRP, 'dictation_language' => 'en']);
        $say($student);
        $this->assertSame(Dictation::WHISPER, $model());
        LearnerTables::query(LearnerScope::of($on), 'engine_settings')->update(['dictation_language' => 'am']);
        $this->heard = ['ሰላም'];
        $say($student);
        $this->assertSame(Dictation::GEMINI, $model());
    }

    public function test_the_chat_has_one_gear_for_the_model_and_voice_typing_and_no_formatting_buttons(): void
    {
        $this->withoutVite();
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(TutorChat::class, ['vistud' => true])
            ->assertSee('aria-label="Chat settings"', false)->assertSee('All AI settings')->assertSee('Voice typing')->assertSee("I'll speak", false)
            ->assertDontSee('aria-label="Text formatting"', false)->assertDontSee('aria-label="Voice typing settings"', false);
    }

    public function test_a_failed_voice_request_leaves_a_log_line_with_what_was_tried_and_never_the_audio(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->failWith = 502;
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            if ($event->message === 'dictation failed') {
                $logged[] = $event->context;
            }
        });

        $this->actingAs($user)->post(route('dictation'), ['audio' => UploadedFile::fake()->createWithContent('speech.mp4', 'secret words in the audio')], ['User-Agent' => 'iPhone Safari'])
            ->assertStatus(422)->assertJsonPath('error.code', 'dictation_failed');
        $this->assertCount(1, $logged);
        [$line] = $logged;
        $this->assertSame(['mp4', 25, 'dictation_failed', 'iPhone Safari'], [$line['format'], $line['bytes'], $line['code'], $line['agent']]);
        $this->assertSame([Dictation::WHISPER, 502, 'Provider returned error'], [$line['calls'][0]['model'], $line['calls'][0]['status'], $line['calls'][0]['error']]);
        $this->assertArrayHasKey('seconds', $line);
        $this->assertStringNotContainsString('secret words', json_encode($logged));

        // An upload that never arrived is logged too.
        $this->actingAs($user)->post(route('dictation'), [])->assertStatus(422);
        $this->assertSame('audio', $logged[1]['code']);
    }

    public function test_the_whole_request_keeps_within_the_web_servers_limit(): void
    {
        // Each call's time is what is left of the budget, so the two calls of a retry never pass it together.
        $this->assertLessThan(60, Dictation::BUDGET);
    }

    public function test_the_course_guide_records_too(): void
    {
        $this->withoutVite();
        $user = $this->student();
        $course = app(Workspaces::class)->create($this->principal($user), ['name' => 'Physics']);
        $this->actingAs($user)->get(route('workspaces.guide', $course->id))->assertOk()
            ->assertSee('data-dictation-url="'.route('dictation').'"', false);
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
