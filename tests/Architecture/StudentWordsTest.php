<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The words a student reads (docs/specs/vistud-2-blueprint.md §3.2, §3.11). The code keeps its names (a `workspace`, a
 * `finding`, the `journal`); the screens say Course, Key point and Your data, and never say the internal words:
 * evidence, write-back, briefing, journal, engine. A student-facing view or notice that contains one fails here.
 *
 * It reads what the student can read: the text between tags, a few attributes (title, aria-label, placeholder, label, hint,
 * alt), the labels written in a view's PHP (a capitalised string like 'Notes & files'), and the words of the notices and
 * errors the screens' components give. Admin pages are for the owner and are left out.
 */
class StudentWordsTest extends TestCase
{
    /** Where student-facing views are. */
    private const VIEWS = 'resources/views';

    /** Not for students: the owner's pages. */
    private const EXEMPT = ['resources/views/admin/', 'resources/views/components/admin/', 'resources/views/livewire/admin/'];

    /** Components that give a student their notices and errors. */
    private const COMPONENTS = 'app/Livewire';

    /** The owner's own navigation lives in the shared layout; these labels are for the admin and no one else. */
    private const OWNER_LABELS = ['resources/views/components/layouts/app.blade.php' => ['Overview', 'AI engine', 'ViStud admin overview']];

    private const FORBIDDEN = [
        'workspace' => '/\bworkspaces?\b/i',
        'evidence' => '/\bevidence\b/i',
        'write-back' => '/\bwrite[- ]?backs?\b/i',
        'briefing' => '/\bbriefings?\b/i',
        'journal' => '/\bjournal\b/i',
        'engine' => '/\bengine\b/i',
        'finding' => '/\bfindings?\b/i',
        'overview' => '/\boverview\b/i',
        "the tutor's marks" => '/\\b(?:find the|tutor.s) marks\\b/i',
    ];

    public function test_no_student_view_says_an_internal_word(): void
    {
        $offenders = [];
        foreach ($this->files(self::VIEWS, '.blade.php') as $file) {
            if ($this->exempt($file)) {
                continue;
            }
            foreach ($this->wordsIn(file_get_contents(base_path($file))) as $text) {
                if (in_array(trim($text), self::OWNER_LABELS[$file] ?? [], true)) {
                    continue;
                }
                foreach (self::FORBIDDEN as $what => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $offenders[] = "{$file}: “{$what}” in “".mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 80).'”';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'A student never reads these words (docs/specs/vistud-2-blueprint.md §3.2): Course, Key point, Your data, Home.');
    }

    public function test_no_notice_or_error_a_component_gives_says_an_internal_word(): void
    {
        $offenders = [];
        foreach ($this->files(self::COMPONENTS, '.php') as $file) {
            if (str_contains($file, 'Livewire/Admin/')) {
                continue;
            }
            $code = file_get_contents(base_path($file));
            preg_match_all('/(?:->notify\(|->addError\([^,]+,|->flash\([^,]+,|notice\s*=)\s*(["\'])((?:\\\\.|(?!\1).)*)\1/s', $code, $found);
            foreach ($found[2] as $text) {
                // What a student reads: a name put in by the code ({$workspace->name}) is not a word of the notice.
                $text = preg_replace('/\{\$[^}]*\}/', ' ', $text);
                foreach (self::FORBIDDEN as $what => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $offenders[] = "{$file}: “{$what}” in “".mb_substr($text, 0, 80).'”';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'A student never reads these words (docs/specs/vistud-2-blueprint.md §3.2).');
    }

    public function test_the_guard_sees_what_it_should(): void
    {
        $view = <<<'BLADE'
            {{-- evidence, in a comment, is fine --}}
            <x-page title="Study record" :back-href="route('journal.index')" hint="Your journal" />
            @php($label = 'Workspace notes')
            <p class="journal-row">The briefing is ready for {{ $workspace->name }}</p>
            <script>const engine = 1;</script>
            BLADE;

        $text = implode(' | ', $this->wordsIn($view));
        $this->assertStringContainsString('Your journal', $text);
        $this->assertStringContainsString('Workspace notes', $text);
        $this->assertStringContainsString('The briefing is ready for', $text);
        $this->assertStringNotContainsString('evidence', $text);
        $this->assertStringNotContainsString('const engine', $text);
        $this->assertStringNotContainsString('journal.index', $text);
        $this->assertStringNotContainsString('journal-row', $text);
    }

    /** The text of a Blade source a student can read: the text between tags, some attributes and capitalised labels in its PHP. */
    private function wordsIn(string $source): array
    {
        $words = [];
        $source = preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '#<script\b.*?</script>#is', '#<style\b.*?</style>#is'], '', $source);

        // Labels written in PHP: a capitalised string of words, never a route or a class name.
        $php = [];
        preg_match_all('/@php\b(?:\((.*?)\)\s*$|(.*?)@endphp)/ms', $source, $blocks);
        foreach (array_merge($blocks[1], $blocks[2]) as $block) {
            $php[] = $block;
        }
        preg_match_all('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}|@(?:include|class|props|if|elseif|foreach|php)\b\s*\((.*?)\)\s*$/ms', $source, $expressions);
        foreach (array_merge($expressions[1], $expressions[2], $expressions[3], $php) as $code) {
            preg_match_all("/'([A-Z][A-Za-z0-9 ,.’&…!?:-]{2,})'/", $code, $labels);
            foreach ($labels[1] as $label) {
                if (str_contains($label, ' ') || ctype_alpha($label)) {
                    $words[] = $label;
                }
            }
        }

        // Attributes a student reads.
        preg_match_all('/\s(?:title|aria-label|placeholder|label|hint|alt|back-to|busy-label|count-label|eyebrow|context)="([^"{]*)"/', $source, $attributes);
        array_push($words, ...$attributes[1]);

        // The text between tags, with Blade's code taken out.
        $text = preg_replace(['/@php\b.*?@endphp/s', '/@php\(.*?\)\s*$/m', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], ' ', $source);
        $text = preg_replace('/@\w+\s*(\((?:[^()]++|(?1))*\))?/', ' ', $text);
        // A tag, whatever its quoted attributes hold (Alpine and Blade expressions have > in them).
        $text = preg_replace('/<[a-zA-Z\/][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>/s', "\n", $text);
        foreach (preg_split('/\n+/', $text) as $line) {
            if (trim($line) !== '') {
                $words[] = $line;
            }
        }

        return $words;
    }

    private function exempt(string $file): bool
    {
        foreach (self::EXEMPT as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function files(string $folder, string $suffix): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getPathname(), $suffix)) {
                // Relative to the project, with forward slashes (a Windows path has backslashes).
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
