<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Platform\Files\TempWorkspace;
use App\Platform\Ids;
use App\Study\AudioPieces;
use App\Study\Input;
use App\Study\Sessions;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Voice typing that works on any device (the owner's ask, 2026-10-23): the page records what the student says, and
 * this turns it into words with the transcription model they chose in their AI settings (MODELS: Whisper by default,
 * Gemini for Amharic and other less common languages), through OpenRouter's /audio/transcriptions with their key (or
 * the shared one, which counts it against the day's limit). The recording is made mono speech-quality mp3 when ffmpeg
 * is there, in private temporary storage, and kept nowhere.
 */
final class Dictation
{
    public const WHISPER = 'openai/whisper-large-v3-turbo';

    public const GEMINI = 'google/gemini-3.5-transcribe';

    /** @var array<string, array{name: string, hint: string}> */
    public const MODELS = [
        self::WHISPER => ['name' => 'Whisper Large V3 Turbo', 'hint' => 'Fast and the cheapest. Good for most languages.'],
        self::GEMINI => ['name' => 'Gemini 3.5 Transcribe', 'hint' => 'Better for Amharic and other less common languages.'],
    ];

    /**
     * The languages "Teach me in" offers, as the service names them (ISO-639-1): told to the model, so it hears Amharic
     * as Amharic. Another language (or none) leaves the model to tell which it is.
     */
    public const LANGUAGES = [
        'english' => 'en', 'amharic' => 'am', 'afaan oromo' => 'om', 'oromo' => 'om', 'tigrinya' => 'ti', 'somali' => 'so',
        'arabic' => 'ar', 'french' => 'fr', 'spanish' => 'es', 'portuguese' => 'pt', 'swahili' => 'sw', 'hindi' => 'hi',
        'chinese' => 'zh', 'german' => 'de', 'italian' => 'it', 'turkish' => 'tr',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** The longest recording turned into words, in seconds. */
    public const MAX_SECONDS = 300;

    /** What a recording may be, by its extension (phones record webm, mp4 or m4a; a few ogg or wav). */
    public const FORMATS = ['webm', 'mp4', 'm4a', 'ogg', 'oga', 'wav', 'mp3', 'aac', 'flac'];

    public function __construct(private Settings $settings, private Setup $setup, private Usage $usage, private Sessions $sessions) {}

    /** The model a stored choice means: one of MODELS, else the default. */
    public static function model(?string $id): string
    {
        return is_string($id) && isset(self::MODELS[$id]) ? $id : self::WHISPER;
    }

    /**
     * The words in a recording.
     *
     * @throws Unprocessable when there is no key for it, no consent, the day's shared allowance is used, or the service fails
     */
    public function transcribe(Principal $by, string $bytes, string $extension): string
    {
        $extension = strtolower($extension);
        Input::refuse($bytes === '' || strlen($bytes) > self::MAX_BYTES ? ['audio' => 'Record something shorter than five minutes.'] : []);
        Input::refuse(! in_array($extension, self::FORMATS, true) ? ['audio' => 'That recording can\'t be read.'] : []);
        $choices = $this->settings->get($by);
        $key = $this->settings->credential($by);
        if ($key === '') {
            throw new Unprocessable('engine_key', 'Add your key in your AI settings first.');
        }
        [$provider, $secret] = Credential::unpack($key);
        if ($provider !== Providers::OPENROUTER) {
            throw new Unprocessable('dictation_key', 'Voice typing needs an OpenRouter key. Add one in your AI settings.');
        }
        if ($choices->consentedAt === null) {
            throw new Unprocessable('engine_consent', 'Agree to the chat in your AI settings first.');
        }
        $shared = $this->settings->shared($by);
        if ($shared !== null && $key === $shared['key']) {
            $this->usage->refuseOverSharedDaily(Guard::learner($by), $shared['daily_micros'], $this->sessions->timezone($by));
        }

        [$audio, $format] = self::speech($bytes, $extension);
        try {
            $response = Http::withToken((string) $secret)->acceptJson()->timeout(90)
                ->attach('file', $audio, 'speech.'.$format)
                ->post(rtrim($this->setup->url(), '/').'/audio/transcriptions', array_filter([
                    'model' => $choices->dictationModel,
                    'provider' => json_encode(['data_collection' => $choices->noTraining ? 'deny' : 'allow']),
                    // The language they chose to be taught in, when it is one the service knows.
                    'language' => self::LANGUAGES[mb_strtolower(trim((string) $choices->language))] ?? null,
                ], fn ($value) => $value !== null));
        } catch (Throwable) {
            throw new Unprocessable('dictation_failed', 'The voice service couldn\'t be reached. Try again.');
        }
        if (! $response->successful()) {
            throw new Unprocessable('dictation_failed', match ($response->status()) {
                401, 403 => 'Your AI key was refused. Check it in your AI settings.',
                402 => 'The AI account is out of credit.',
                429 => 'Too many at once. Wait a moment, then try again.',
                default => 'Your words couldn\'t be written down. Try again.',
            });
        }
        $cost = (int) round(((float) ($response->json('usage.cost') ?? 0)) * 1_000_000);
        if ($cost > 0) {
            // Counted with what the AI costs this month (and today, for the shared key's limit).
            LearnerTables::insert(Guard::learner($by), 'engine_jobs', [
                'id' => Ids::new(), 'workspace_id' => null, 'role' => Role::Helper->value, 'kind' => 'dictation', 'status' => 'done',
                'cost_micros' => $cost, 'created_at' => now(), 'finished_at' => now(),
            ]);
        }

        return trim((string) ($response->json('text') ?? ''));
    }

    /**
     * The recording as speech-quality mono mp3, which every transcription model takes, cut at MAX_SECONDS; as it came
     * when ffmpeg isn't there or can't read it (the services take webm, m4a, ogg and wav too).
     *
     * @return array{0: string, 1: string} the bytes and their format
     */
    private static function speech(string $bytes, string $extension): array
    {
        try {
            $work = new TempWorkspace;
        } catch (Throwable) {
            return [$bytes, $extension === 'mp4' ? 'm4a' : $extension];
        }
        try {
            $in = $work->path.'/in.'.$extension;
            $out = $work->path.'/out.mp3';
            file_put_contents($in, $bytes);
            $process = new Process([AudioPieces::binary(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $in, '-t', (string) self::MAX_SECONDS,
                '-vn', '-ac', '1', '-ar', '16000', '-b:a', '32k', $out], null, ['TMPDIR' => $work->path, 'TMP' => $work->path, 'TEMP' => $work->path]);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() && is_file($out) && filesize($out) > 0
                ? [(string) file_get_contents($out), 'mp3']
                : [$bytes, $extension === 'mp4' ? 'm4a' : $extension];
        } catch (Throwable) {
            return [$bytes, $extension === 'mp4' ? 'm4a' : $extension];
        } finally {
            $work->close();
        }
    }
}
