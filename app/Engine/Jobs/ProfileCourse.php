<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\FileText;
use App\Study\FileTexts;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * The reader reads a course's syllabus (docs/specs/vistud-2-blueprint.md §3.5.2): the file the student gave, or the
 * text they pasted, in; the course's profile out (what it is about, what it should teach, how it is assessed, its
 * textbook) and the modules it lists, proposed for the student to tick. Nothing is added to the course by this job:
 * the student reviews it first (App\Study\CourseProfiles::addFromReading). The syllabus is read from where the
 * student left it when the job runs, never from the job's own payload.
 */
final class ProfileCourse extends Job
{
    public const PROMPT = 'resources/prompts/reader-course.md';

    public function __construct(string $workspaceId)
    {
        parent::__construct($workspaceId, 'workspace', $workspaceId);
    }

    public function kind(): string
    {
        return 'profile_course';
    }

    public function handle(Principal $by, Run $run): void
    {
        $text = $this->syllabus($by);
        if ($text === '') {
            $run->skip('no_text');
        }
        $today = CarbonImmutable::now()->toDateString();
        $reply = $run->ask(self::rules(), "Today is {$today}. The syllabus is between the quotes.\n\"\"\"\n{$text}\n\"\"\"", 3_500);
        $reading = self::parse($reply->text);

        app(CourseProfiles::class)->applyReading($by, $this->workspaceId, $reading, $reply->model);
    }

    /** The reader's rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * What the answer says, cleaned and checked: text cut to its limits, kinds and dates only as the app knows them,
     * a module list in order. An answer that isn't an object, or says nothing usable, is a failure to try again.
     *
     * @return array{about: string, outcomes: list<string>, assessment: list<array{name: string, kind: string, weight: ?int, due_on: ?string}>, textbook: string, modules: list<array{title: string, starts_on: ?string, ends_on: ?string}>}
     *
     * @throws EngineFailed
     */
    public static function parse(string $answer): array
    {
        $data = self::object($answer);
        $clean = fn (mixed $value, int $limit) => is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $limit) : '';
        $date = function (mixed $value): ?string {
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
        };

        $outcomes = [];
        foreach (is_array($data['outcomes'] ?? null) ? $data['outcomes'] : [] as $line) {
            $line = $clean($line, CourseProfiles::MAX_OUTCOME);
            if ($line !== '' && count($outcomes) < CourseProfiles::MAX_OUTCOMES) {
                $outcomes[] = $line;
            }
        }

        $assessment = [];
        foreach (is_array($data['assessment'] ?? null) ? $data['assessment'] : [] as $item) {
            $name = is_array($item) ? $clean($item['name'] ?? null, CourseProfiles::MAX_NAME) : '';
            if ($name === '' || count($assessment) >= CourseProfiles::MAX_ASSESSMENTS) {
                continue;
            }
            $weight = filter_var($item['weight'] ?? null, FILTER_VALIDATE_INT);
            $kind = $item['kind'] ?? null;
            $assessment[] = [
                'name' => $name,
                'kind' => is_string($kind) && isset(Activities::KINDS[$kind]) ? $kind : 'other',
                'weight' => $weight !== false && $weight >= 0 && $weight <= 100 ? $weight : null,
                'due_on' => $date($item['due_on'] ?? null),
            ];
        }

        $modules = [];
        foreach (is_array($data['modules'] ?? null) ? $data['modules'] : [] as $item) {
            $title = is_array($item) ? $clean($item['title'] ?? null, 120) : '';
            if ($title === '' || count($modules) >= CourseProfiles::MAX_MODULES) {
                continue;
            }
            $starts = $date($item['starts_on'] ?? null);
            $ends = $date($item['ends_on'] ?? null);
            $modules[] = ['title' => $title, 'starts_on' => $starts, 'ends_on' => $ends !== null && $starts !== null && $ends < $starts ? null : $ends];
        }

        $reading = [
            'about' => $clean($data['about'] ?? null, CourseProfiles::MAX_ABOUT),
            'outcomes' => $outcomes,
            'assessment' => $assessment,
            'textbook' => $clean($data['textbook'] ?? null, CourseProfiles::MAX_TEXTBOOK),
            'modules' => $modules,
        ];
        if ($reading['about'] === '' && $outcomes === [] && $assessment === [] && $modules === [] && $reading['textbook'] === '') {
            throw new EngineFailed('engine_unreadable', 'The AI found nothing in it. Check that it is the syllabus, or try again.');
        }

        return $reading;
    }

    /** The JSON object in an answer, even inside a code fence or after a word of introduction. */
    private static function object(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be read. Try again.');
        }

        return $data;
    }

    /** What the syllabus says, as one text of at most CourseProfiles::MAX_SYLLABUS characters; empty when it has no words. */
    private function syllabus(Principal $by): string
    {
        ['file' => $file, 'text' => $pasted] = app(CourseProfiles::class)->syllabus($by, $this->workspaceId);
        if ($file === null) {
            return trim((string) $pasted);
        }
        $read = app(FileTexts::class)->of($by, $file);
        if ($read->state === FileText::PREPARING) {
            throw new Unprocessable('file_preparing', 'The file is still being prepared. Try again in a minute.');
        }
        if (! $read->hasWords()) {
            return '';
        }

        return mb_substr(trim(implode("\n\n", array_map('trim', $read->pages))), 0, CourseProfiles::MAX_SYLLABUS);
    }
}
