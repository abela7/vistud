<?php

namespace App\Livewire\Study;

use App\Engine\TokenUsage;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The tokens a student's AI use took, in AI settings (App\Engine\TokenUsage, the owner's ask, 2026-10-23): today, this
 * week, this month and all time, and for the period chosen, by area, course and model.
 */
#[Lazy]
final class TokenUsagePanel extends Component
{
    /** The period the breakdowns are for (TokenUsage::PERIODS). */
    public string $period = 'month';

    private TokenUsage $usage;

    private PrincipalFactory $principals;

    public function boot(TokenUsage $usage, PrincipalFactory $principals): void
    {
        [$this->usage, $this->principals] = [$usage, $principals];
    }

    public function placeholder(): string
    {
        return '<section class="acc"><p class="acc-note">Counting tokens…</p></section>';
    }

    public function render(): View
    {
        return view('livewire.study.token-usage-panel', ['report' => $this->usage->report($this->principal(), $this->period)]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
