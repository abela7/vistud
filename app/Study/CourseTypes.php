<?php

namespace App\Study;

/**
 * What kind of course it is, what level it is taught at, and where the student starts from: fixed lists, so every AI
 * reads the same words for the same thing ("computing", never "IT" one day and "CS" the next). The type is the
 * course's (`workspaces.type`, docs/specs/workspaces.md §4: "workspace types … each is a plugin"), and it turns on the
 * subject plugins that suit it (App\Study\Plugins) in every new session, which the student can still change. A course
 * whose type was never chosen is `general`, with no plugins.
 */
final class CourseTypes
{
    /** A course whose type was never chosen. */
    public const NONE = 'general';

    /** key => [label, icon, the plugins it turns on, how the tutor is told] */
    public const TYPES = [
        'computing' => ['Computing & IT', 'code', ['coding'], 'computing and IT'],
        'mathematics' => ['Mathematics', 'sigma', ['math_physics'], 'mathematics'],
        'physics_engineering' => ['Physics & engineering', 'calculator', ['math_physics'], 'physics and engineering'],
        'chemistry' => ['Chemistry', 'flask-conical', ['math_physics'], 'chemistry'],
        'biology_medicine' => ['Biology & medicine', 'microscope', ['biology_med'], 'biology and medicine'],
        'law' => ['Law', 'landmark', ['law'], 'law'],
        'languages' => ['Languages', 'languages', ['languages'], 'a language'],
        'business_economics' => ['Business & economics', 'globe', [], 'business and economics'],
        'humanities' => ['Humanities & social sciences', 'book-open', [], 'the humanities and social sciences'],
        'arts_design' => ['Arts & design', 'palette', [], 'arts and design'],
        'other' => ['Something else', 'brain', [], ''],
    ];

    /** key => [label, how the tutor is told] */
    public const LEVELS = [
        'school' => ['School', 'school'],
        'foundation' => ['Foundation', 'foundation or pre-university'],
        'undergraduate' => ['Undergraduate', 'undergraduate university'],
        'postgraduate' => ['Postgraduate', 'postgraduate university'],
        'professional' => ['Professional', 'a professional qualification'],
        'self_study' => ['On my own', 'self-study, outside a programme'],
    ];

    /** Where the student starts from in this course: key => [label, how the tutor is told]. */
    public const BACKGROUNDS = [
        'new' => ['New to it', 'new to the subject: build from the ground up'],
        'some' => ['Some background', 'has some background: connect to what they know'],
        'revising' => ['Revising', 'has studied it before and is revising: go briskly, test more'],
    ];

    public static function chosen(?string $type): bool
    {
        return $type !== null && isset(self::TYPES[$type]);
    }

    public static function label(?string $type): ?string
    {
        return self::chosen($type) ? self::TYPES[$type][0] : null;
    }

    /** The plugins a type turns on by default; none for a course without one. @return list<string> */
    public static function plugins(?string $type): array
    {
        return self::chosen($type) ? self::TYPES[$type][2] : [];
    }

    /** "Type: computing and IT" for the tutor, or null when there is nothing to say. */
    public static function told(?string $type): ?string
    {
        return self::chosen($type) && self::TYPES[$type][3] !== '' ? self::TYPES[$type][3] : null;
    }

    /**
     * What an AI is told about the kind of course, one short line each, only what is known: "Kind: computing and IT ·
     * undergraduate university · taught in English", "Tools and notation: Python, SQL", "Exams: closed book, 2 hours".
     *
     * @return list<string>
     */
    public static function lines(?string $type, CourseProfileDetails $profile): array
    {
        $kind = array_filter([
            self::told($type),
            $profile->level !== null && isset(self::LEVELS[$profile->level]) ? self::LEVELS[$profile->level][1] : null,
            $profile->language !== null ? "taught in {$profile->language}" : null,
        ]);

        return array_values(array_filter([
            $kind === [] ? null : 'Kind: '.implode(' · ', $kind),
            $profile->tools === [] ? null : 'Tools and notation: '.implode(', ', $profile->tools),
            $profile->examFormat === null ? null : "Exams: {$profile->examFormat}",
        ]));
    }
}
