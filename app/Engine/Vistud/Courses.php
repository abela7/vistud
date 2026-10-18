<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Lookup;
use App\Platform\Access\Principal;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;

/** Finds the course ViStud AI names, among every course of the student's (archived ones too): by its name, its code, or a part of its name. */
final class Courses
{
    public function __construct(private Workspaces $workspaces) {}

    /**
     * Every course of the student's: the active ones in their order, then the archived ones.
     *
     * @return list<WorkspaceDetails>
     */
    public function all(Principal $by): array
    {
        return [...$this->workspaces->list($by), ...$this->workspaces->list($by, true)];
    }

    /** @return WorkspaceDetails|string the course, or words saying why none was picked */
    public function find(Principal $by, mixed $wanted, bool $exact = false): WorkspaceDetails|string
    {
        $wanted = is_string($wanted) ? trim($wanted) : '';
        $all = $this->all($by);
        if ($wanted === '') {
            return 'Say which course: its name, as the courses tool gives it.';
        }
        $key = Lookup::key($wanted);
        $passes = [
            fn (WorkspaceDetails $w) => $w->id === $wanted,
            fn (WorkspaceDetails $w) => Lookup::key($w->name) === $key || ($w->code !== null && Lookup::key($w->code) === $key),
        ];
        if (! $exact) {
            $passes[] = fn (WorkspaceDetails $w) => mb_stripos($w->name, $wanted) !== false || ($w->code !== null && mb_stripos($w->code, $wanted) !== false);
        }
        foreach ($passes as $fits) {
            $found = array_values(array_filter($all, $fits));
            if (count($found) === 1) {
                return $found[0];
            }
            if (count($found) > 1) {
                return "Several courses match \"{$wanted}\": ".implode('; ', array_map(fn (WorkspaceDetails $w) => "\"{$w->name}\" (id {$w->id})", array_slice($found, 0, 8))).'. Say which one.';
            }
        }

        return "There is no course called \"{$wanted}\". The courses tool lists them all.";
    }
}
