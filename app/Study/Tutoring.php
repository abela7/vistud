<?php

namespace App\Study;

/**
 * How the assistant should teach in a study session
 * (docs/specs/study-memory.md §4.3): four choices the student makes when a
 * session starts, and the tutoring prompt they fill in. The prompt itself is
 * a plain text file anyone can read and edit (resources/prompts/tutor.md).
 */
final class Tutoring
{
    /** The prompt file, relative to the app's root. */
    public const PROMPT = 'resources/prompts/tutor.md';

    /** What each choice is called on screen. */
    public const QUESTIONS = [
        'method' => 'How to teach',
        'check_ins' => 'Check questions',
        'quiz' => 'Understanding level',
        'pace' => 'Pace',
    ];

    private const ALIASES = [
        'quiz' => [
            'beginner' => 'easy',
            'intermediate' => 'normal',
            'master' => 'exam',
            'mastered' => 'exam',
            'advanced' => 'exam',
        ],
    ];

    /** Each choice: [its label on screen, what it asks of the assistant in the prompt]. */
    public const CHOICES = [
        'method' => [
            'explain' => ['Explain, then check', 'Explain first: one idea at a time, in plain words, with a concrete example. Any question about an idea comes after you have explained it.'],
            'analogy' => ['Analogies first', 'Lead with intuitive real-world analogies and concrete metaphors before technical definitions or formulas. Ground abstract concepts in relatable imagery.'],
            'socratic' => ['Socratic', 'Lead with questions: ask what the student thinks, and give hints and small steps so they work each idea out themselves. Explain directly only when they are stuck after trying. These teaching questions are not check questions.'],
            'summary' => ['Summary first', 'Start each part with the big picture in a few lines (what it is, why it matters, how it connects to what they know), then go through it in detail.'],
            'steps' => ['Step by step', 'Break everything into small numbered steps. Give one step at a time, and wait for the student before the next.'],
        ],
        'check_ins' => [
            'section' => ['After every section', 'Check questions: one at the end of each section, and the student answers before you go on. None in the middle of a section.'],
            'end' => ['At the end', 'Check questions: none while going through the material. At the end, run a short quiz on what was covered, one question per message.'],
            'none' => ['None', 'Check questions: none, and no quizzes, unless the student asks for them.'],
        ],
        'quiz' => [
            'easy' => ['Beginner', 'Student understanding level is beginner: explain concepts simply from first principles in plain, beginner-friendly terms. Avoid unexplained jargon or technical shorthand. Use intuitive everyday analogies, step-by-step breakdowns, and concrete relatable examples. Keep tone encouraging and accessible, and keep any check questions gentle, focusing on basic recall and fundamental definitions.'],
            'normal' => ['Intermediate', 'Student understanding level is intermediate: explain ideas with balanced depth and clarity using standard domain terminology and practical applied examples. Connect new concepts to foundations without oversimplifying or overcomplicating. Check questions ask the student to explain ideas and apply them to short realistic scenarios.'],
            'exam' => ['Master', 'Student understanding level is master / advanced: explain with high technical rigor, conciseness, and depth without hand-holding or repeating basics. Cover edge cases, nuances, trade-offs, and exam-level deep problem solving.'],
        ],
        'pace' => [
            'slide' => ['One slide at a time', 'A part is one slide or page. Go through the material one part at a time, and wait for the student to say they\'re ready for the next.'],
            'section' => ['A section at a time', 'A part is one section: the slides or pages that belong together. Go through the material a part at a time, and wait for the student before the next.'],
        ],
    ];

    public const DEFAULTS = ['method' => 'explain', 'check_ins' => 'section', 'quiz' => 'normal', 'pace' => 'slide'];

    public const MAX_CUSTOM = 1500;

    /**
     * Checked choices; a missing one takes its default, an unknown one is refused.
     * Custom instructions (custom_do, custom_dont) are optional and trimmed up to 1500 characters.
     *
     * @return array{method: string, check_ins: string, quiz: string, pace: string, custom_do?: string, custom_dont?: string}
     */
    public static function validated(?array $input): array
    {
        $choices = [];
        $errors = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? null;
            $value = $value === null || $value === '' ? $default : $value;
            if (is_string($value) && isset(self::ALIASES[$key][$value])) {
                $value = self::ALIASES[$key][$value];
            }
            if (! is_string($value) || ! isset(self::CHOICES[$key][$value])) {
                $errors["tutoring.{$key}"] = 'Choose one of the options.';
            }
            $choices[$key] = is_string($value) ? $value : $default;
        }

        $do = isset($input['custom_do']) && is_string($input['custom_do']) ? trim($input['custom_do']) : (isset($input['do']) && is_string($input['do']) ? trim($input['do']) : '');
        if ($do !== '') {
            if (mb_strlen($do) > self::MAX_CUSTOM) {
                $errors['tutoring.custom_do'] = 'Must not exceed '.self::MAX_CUSTOM.' characters.';
            } else {
                $choices['custom_do'] = $do;
            }
        }

        $dont = isset($input['custom_dont']) && is_string($input['custom_dont']) ? trim($input['custom_dont']) : (isset($input['dont']) && is_string($input['dont']) ? trim($input['dont']) : '');
        if ($dont !== '') {
            if (mb_strlen($dont) > self::MAX_CUSTOM) {
                $errors['tutoring.custom_dont'] = 'Must not exceed '.self::MAX_CUSTOM.' characters.';
            } else {
                $choices['custom_dont'] = $dont;
            }
        }

        $plugins = Plugins::validIds($input['plugins'] ?? null);
        if ($plugins !== []) {
            $choices['plugins'] = $plugins;
        }

        Input::refuse($errors);

        return $choices;
    }

    /** Stored choices, with defaults for anything missing or unknown (an older session, say). */
    public static function normalised(?array $stored): array
    {
        $choices = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if (is_string($value) && isset(self::ALIASES[$key][$value])) {
                $value = self::ALIASES[$key][$value];
            }
            $choices[$key] = is_string($value) && isset(self::CHOICES[$key][$value]) ? $value : $default;
        }

        $do = $stored['custom_do'] ?? ($stored['do'] ?? null);
        if (is_string($do) && trim($do) !== '') {
            $choices['custom_do'] = trim($do);
        }

        $dont = $stored['custom_dont'] ?? ($stored['dont'] ?? null);
        if (is_string($dont) && trim($dont) !== '') {
            $choices['custom_dont'] = trim($dont);
        }

        $plugins = Plugins::validIds($stored['plugins'] ?? null);
        if ($plugins !== []) {
            $choices['plugins'] = $plugins;
        }

        return $choices;
    }

    /** "Explain, then check · checks after every section · intermediate level · one slide at a time" */
    public static function summary(array $choices): string
    {
        $choices = self::normalised($choices);
        $checks = ['section' => 'checks after every section', 'end' => 'a quiz at the end', 'none' => 'no check questions'][$choices['check_ins']];

        $summary = implode(' · ', [
            self::CHOICES['method'][$choices['method']][0],
            $checks,
            strtolower(self::CHOICES['quiz'][$choices['quiz']][0]).' level',
            strtolower(self::CHOICES['pace'][$choices['pace']][0]),
        ]);

        if (! empty($choices['plugins'])) {
            $names = array_map(fn (PluginDetails $p) => $p->name, Plugins::active($choices['plugins']));
            if ($names !== []) {
                $summary .= ' · plugins: '.implode(', ', $names);
            }
        }

        return $summary;
    }

    /** The tutoring prompt with the choices filled in; the file's opening comment (for people) is left out. */
    public static function prompt(array $choices): string
    {
        $choices = self::normalised($choices);
        $template = (string) file_get_contents(base_path(self::PROMPT));
        $template = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $template);
        $fill = [];
        foreach (self::DEFAULTS as $key => $default) {
            $fill['{{'.$key.'}}'] = self::CHOICES[$key][$choices[$key]][1];
        }

        $rendered = trim(strtr($template, $fill));

        $custom = [];
        if (! empty($choices['custom_do'])) {
            $custom[] = "- What to do: {$choices['custom_do']}";
        }
        if (! empty($choices['custom_dont'])) {
            $custom[] = "- What NOT to do: {$choices['custom_dont']}";
        }
        if ($custom !== []) {
            $rendered .= "\n\n".Fence::wrap("## Custom instructions\n\n".implode("\n", $custom));
        }

        $pluginPrompt = Plugins::prompt($choices['plugins'] ?? []);
        if ($pluginPrompt !== null) {
            $rendered .= "\n\n{$pluginPrompt}";
        }

        return $rendered;
    }
}
