<?php

namespace App\Engine;

/**
 * The three jobs a model does in ViStud (docs/specs/vistud-2-blueprint.md §3.6.1). The student chooses a model for
 * each: the tutor teaches in study sessions, the reader reads files and writes the small records from them, and
 * the helper does quick jobs on every page. A role left empty uses the next one up (Choices::modelFor).
 */
enum Role: string
{
    case Tutor = 'tutor';
    case Reader = 'reader';
    case Helper = 'helper';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** One line on what the role does, as the settings page says it. */
    public function does(): string
    {
        return match ($this) {
            self::Tutor => 'Teaches in sessions.',
            self::Reader => 'Reads files, writes summaries and cards.',
            self::Helper => 'Quick edits and questions.',
        };
    }
}
