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

    /** What the microphone's settings offer to speak, by code; `auto` leaves the model to tell which it is. */
    public const SPOKEN = [
        'auto' => 'Detect it', 'am' => 'Amharic', 'en' => 'English', 'om' => 'Afaan Oromo', 'ti' => 'Tigrinya', 'so' => 'Somali',
        'ar' => 'Arabic', 'fr' => 'French', 'es' => 'Spanish', 'pt' => 'Portuguese', 'sw' => 'Swahili', 'hi' => 'Hindi', 'zh' => 'Chinese',
    ];

    /** Languages written in the Ethiopic script (ፊደል): a transcript of them in Arabic letters was misheard. */
    private const ETHIOPIC = ['am', 'ti'];

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

    /** A stored language for voice typing: one of SPOKEN, or null (follow "Teach me in"). */
    public static function spoken(?string $code): ?string
    {
        return is_string($code) && isset(self::SPOKEN[$code]) ? $code : null;
    }

    /** The code told to the model: the one set beside the microphone, else "Teach me in"'s; null to let it tell. */
    public static function languageFor(Choices $choices): ?string
    {
        if ($choices->dictationLanguage !== null) {
            return $choices->dictationLanguage === 'auto' ? null : $choices->dictationLanguage;
        }

        return self::LANGUAGES[mb_strtolower(trim((string) $choices->language))] ?? null;
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
        $language = self::languageFor($choices);
        $text = $this->ask($by, (string) $secret, $audio, $format, $choices->dictationModel, $language, $choices->noTraining);
        // Amharic or Tigrinya heard as Arabic (models mix them up): asked once more of the other model, and refused
        // rather than put in the box if it still isn't in the Ethiopic script.
        if (in_array($language, self::ETHIOPIC, true) && ! self::ethiopic($text)) {
            $other = $choices->dictationModel === self::WHISPER ? self::GEMINI : self::WHISPER;
            $text = $this->ask($by, (string) $secret, $audio, $format, $other, $language, $choices->noTraining);
            if (! self::ethiopic($text)) {
                $name = self::SPOKEN[$language];
                throw new Unprocessable('dictation_language', $text === ''
                    ? 'Nothing was heard. Try again a little closer to the microphone.'
                    : "It didn't come back in {$name}. Speak a little slower and closer, or try the other model in the microphone's settings.");
            }
        }

        return $text;
    }

    /** Whether some words are in the Ethiopic script (ፊደል) and not mostly in Arabic letters. */
    private static function ethiopic(string $text): bool
    {
        $ethiopic = preg_match_all('/\p{Ethiopic}/u', $text);
        $arabic = preg_match_all('/\p{Arabic}/u', $text);

        return $ethiopic > 0 && $ethiopic >= $arabic;
    }

    /** One call to the service: the words, with what they cost counted with the month's AI use. */
    private function ask(Principal $by, string $secret, string $audio, string $format, string $model, ?string $language, bool $noTraining): string
    {
        try {
            $response = Http::withToken($secret)->acceptJson()->timeout(90)
                ->attach('file', $audio, 'speech.'.$format)
                ->post(rtrim($this->setup->url(), '/').'/audio/transcriptions', array_filter([
                    'model' => $model,
                    'provider' => json_encode(['data_collection' => $noTraining ? 'deny' : 'allow']),
                    // The language they speak, told so it isn't guessed (Amharic is easily taken for Arabic).
                    'language' => $language,
                    'temperature' => $language !== null ? '0' : null,
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
