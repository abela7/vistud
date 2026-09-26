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
        'quiz' => 'Quiz level',
        'pace' => 'Pace',
    ];

    /** Each choice: [its label on screen, what it asks of the assistant in the prompt]. */
    public const CHOICES = [
        'method' => [
            'explain' => ['Explain, then check', 'Explain one idea at a time, in plain words with a concrete example, then check the student can put it in their own words before you move on.'],
            'socratic' => ['Socratic', 'Lead with questions: ask what the student thinks, and give hints and small steps so they work each idea out themselves. Explain directly only when they are stuck after trying.'],
            'summary' => ['Summary first', 'Start each part with the big picture in a few lines (what it is, why it matters, how it connects to what they know), then go through it in detail.'],
            'steps' => ['Step by step', 'Break everything into small numbered steps. Give one step at a time, and wait for the student before the next.'],
        ],
        'check_ins' => [
            'section' => ['After every section', 'After each section, ask one short question to check understanding, and wait for the answer before you go on.'],
            'end' => ['At the end', 'Don\'t stop to check during the material. At the end, run a short quiz on what was covered, one question at a time.'],
            'none' => ['None', 'Don\'t ask check questions or run quizzes unless the student asks for them.'],
        ],
        'quiz' => [
            'easy' => ['Easy', 'Questions ask for recall: definitions, key facts, and what things are called.'],
            'normal' => ['Normal', 'Questions ask the student to explain ideas and apply them to short, new examples.'],
            'exam' => ['Exam level', 'Questions are exam-style: multi-step problems, edge cases, comparing ideas, and justifying the answer.'],
        ],
        'pace' => [
            'slide' => ['One slide at a time', 'Go through the material one slide or page at a time, and wait for the student to say they\'re ready for the next.'],
            'section' => ['A section at a time', 'Go through the material a section at a time (the slides or pages that belong together), and wait for the student before the next section.'],
        ],
    ];

    public const DEFAULTS = ['method' => 'explain', 'check_ins' => 'section', 'quiz' => 'normal', 'pace' => 'slide'];

    /**
     * Checked choices; a missing one takes its default, an unknown one is refused.
     *
     * @return array{method: string, check_ins: string, quiz: string, pace: string}
     */
    public static function validated(?array $input): array
    {
        $choices = [];
        $errors = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? null;
            $value = $value === null || $value === '' ? $default : $value;
            if (! is_string($value) || ! isset(self::CHOICES[$key][$value])) {
                $errors["tutoring.{$key}"] = 'Choose one of the options.';
            }
            $choices[$key] = is_string($value) ? $value : $default;
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
            $choices[$key] = is_string($value) && isset(self::CHOICES[$key][$value]) ? $value : $default;
        }

        return $choices;
    }

    /** "Explain, then check · checks after every section · normal quizzes · one slide at a time" */
    public static function summary(array $choices): string
    {
        $choices = self::normalised($choices);
        $checks = ['section' => 'checks after every section', 'end' => 'a quiz at the end', 'none' => 'no check questions'][$choices['check_ins']];

        return implode(' · ', [
            self::CHOICES['method'][$choices['method']][0],
            $checks,
            strtolower(self::CHOICES['quiz'][$choices['quiz']][0]).' questions',
            strtolower(self::CHOICES['pace'][$choices['pace']][0]),
        ]);
    }

    /** The tutoring prompt with the choices filled in; the file's opening comment (for people) is left out. */
    public static function prompt(array $choices): string
    {
        $choices = self::normalised($choices);
        $template = (string) file_get_contents(base_path(self::PROMPT));
        $template = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $template);
        $fill = [];
        foreach ($choices as $key => $value) {
            $fill['{{'.$key.'}}'] = self::CHOICES[$key][$value][1];
        }

        return trim(strtr($template, $fill));
    }
}
