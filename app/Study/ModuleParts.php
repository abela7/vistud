<?php

namespace App\Study;

/**
 * The kinds of part a module can have (the owner's ask, 2026-10-12). A module is any group studied together; its parts
 * are its folders, and a folder may say which kind it is, so the student sees a lab week as one and the tutor teaches it
 * as one. A folder with no kind is just a folder.
 */
final class ModuleParts
{
    /** key => [label, icon] */
    public const KINDS = [
        'lecture' => ['Lecture', 'presentation'],
        'tutorial' => ['Tutorial', 'users'],
        'lab' => ['Lab', 'flask-conical'],
        'fieldwork' => ['Outside', 'compass'],
        'reading' => ['Reading', 'book-open'],
        'practice' => ['Practice', 'pencil'],
        'project' => ['Project', 'hammer'],
        'assessment' => ['Assessment', 'clipboard-check'],
    ];

    public static function known(?string $kind): bool
    {
        return $kind !== null && isset(self::KINDS[$kind]);
    }

    public static function label(?string $kind): ?string
    {
        return self::known($kind) ? self::KINDS[$kind][0] : null;
    }

    /**
     * The kind to show beside a part's name, or null when the name already says it ("SQL lab" is plainly a lab):
     * the icon says it either way.
     */
    public static function beside(?string $kind, string $name): ?string
    {
        $label = self::label($kind);

        return $label !== null && ! str_contains(mb_strtolower($name), mb_strtolower($label)) ? $label : null;
    }

    /** The folder icon for a part of no kind. */
    public static function icon(?string $kind): string
    {
        return self::known($kind) ? self::KINDS[$kind][1] : 'folder';
    }
}
