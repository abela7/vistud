<?php

namespace App\Livewire\Study;

use App\Identity\PrincipalFactory;
use App\Identity\TimeZones;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * A student's time zone, on the Security part of Settings: the one they have, every IANA zone to choose from by
 * region, and the zone their browser reports with one press to take it (the page reads it with Alpine, so the
 * server never guesses). A thin adapter over App\Identity\TimeZones, which does the checking and the writing.
 */
final class TimezoneSettings extends Component
{
    use Notices;

    public string $timezone = 'UTC';

    private TimeZones $zones;

    private PrincipalFactory $principals;

    public function boot(TimeZones $zones, PrincipalFactory $principals): void
    {
        $this->zones = $zones;
        $this->principals = $principals;
    }

    public function mount(): void
    {
        $this->timezone = $this->zones->current($this->principal());
    }

    /** Keeps the zone chosen in the list. */
    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $this->zones->change($this->principal(), $this->timezone);
        } catch (ValidationException $e) {
            $this->addError('timezone', $e->errors()['timezone'][0] ?? 'Choose a time zone from the list.');

            return;
        }
        $this->notify('Your time zone is saved.');
    }

    /** "Use this": the zone the browser detected, kept in one press. */
    public function useDetected(string $timezone): void
    {
        $this->timezone = $timezone;
        $this->save();
    }

    public function render(): View
    {
        // Every zone the server knows, by region ("Europe" for "Europe/London"), so the list is quick to scan.
        $regions = [];
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $regions[str_contains($zone, '/') ? strstr($zone, '/', true) : 'Other'][] = $zone;
        }

        // The status line tells where things stand: the zone that is saved, not the one being picked (which may be anything).
        $saved = $this->zones->current($this->principal());

        return view('livewire.study.timezone-settings', [
            'regions' => $regions,
            'saved' => $saved,
            'now' => now($saved)->format('H:i'),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
