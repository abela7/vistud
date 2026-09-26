<?php

namespace App\Livewire\Concerns;

use App\Study\Sessions;

/**
 * The Pomodoro fields of a form (starting a session, or a session's
 * Pomodoro settings): a preset, or custom minutes, and whether the next
 * focus period starts by itself. The service checks the numbers.
 */
trait PomodoroForm
{
    /** free or pomodoro. */
    public string $clock = 'free';

    /** classic, deep, short or custom. */
    public string $preset = 'classic';

    public string $focus = '25';

    public string $short = '5';

    public string $long = '15';

    public string $every = '4';

    public bool $auto = true;

    /** @return ?array{focus: int|string, short: int|string, long: int|string, every: int|string, auto: bool} null for the free clock */
    protected function pomodoroInput(): ?array
    {
        if ($this->clock !== 'pomodoro') {
            return null;
        }
        if (isset(Sessions::POMODORO_PRESETS[$this->preset])) {
            [, $focus, $short, $long, $every] = Sessions::POMODORO_PRESETS[$this->preset];

            return ['focus' => $focus, 'short' => $short, 'long' => $long, 'every' => $every, 'auto' => $this->auto];
        }

        return ['focus' => $this->focus, 'short' => $this->short, 'long' => $this->long, 'every' => $this->every, 'auto' => $this->auto];
    }

    /** Fills the fields from settings (null: the free clock, with the classic preset ready). */
    protected function fillPomodoro(?array $settings): void
    {
        $this->clock = $settings === null ? 'free' : 'pomodoro';
        $settings ??= ['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4, 'auto' => true];
        $this->preset = 'custom';
        foreach (Sessions::POMODORO_PRESETS as $key => [, $focus, $short, $long, $every]) {
            if ([$focus, $short, $long, $every] === [(int) $settings['focus'], (int) $settings['short'], (int) $settings['long'], (int) $settings['every']]) {
                $this->preset = $key;
            }
        }
        [$this->focus, $this->short, $this->long, $this->every] = array_map('strval', [$settings['focus'], $settings['short'], $settings['long'], $settings['every']]);
        $this->auto = (bool) $settings['auto'];
    }

    /** The service's field names (pomodoro.focus) as this form's (focus). */
    protected function pomodoroErrorField(string $field): string
    {
        return str_starts_with($field, 'pomodoro.') ? substr($field, 9) : $field;
    }
}
