<?php

namespace App\Livewire\Study;

use App\Engine\TokenUsage;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The AI usage report (App\Engine\TokenUsage, the owner's ask, 2026-10-23): one period at a time, its tokens as one
 * figure and over time as a chart, and where they went, by area, course or model, one list at a time.
 */
final class UsageReport extends Component
{
    #[Url(as: 'p', except: 'month')]
    public string $period = 'month';

    /** The breakdown shown: area, course or model. */
    #[Url(as: 'by', except: 'area')]
    public string $by = 'area';

    private TokenUsage $usage;

    private PrincipalFactory $principals;

    public function boot(TokenUsage $usage, PrincipalFactory $principals): void
    {
        [$this->usage, $this->principals] = [$usage, $principals];
    }

    public function render(): View
    {
        $period = isset(TokenUsage::PERIODS[$this->period]) ? $this->period : 'month';
        $by = in_array($this->by, ['area', 'course', 'model'], true) ? $this->by : 'area';
        $principal = $this->principal();
        $report = $this->usage->report($principal, $period);
        $series = $this->usage->series($principal, $period);

        return view('livewire.study.usage-report', [
            'period' => $period,
            'by' => $by,
            'total' => $report['totals'][$period],
            'series' => $series,
            'scale' => self::nice(max(1, ...array_column($series, 'total'))),
            'parts' => TokenUsage::top($report[['area' => 'areas', 'course' => 'courses', 'model' => 'models'][$by]]),
        ])->title('AI usage');
    }

    /** The chart's top: the peak rounded up to 1, 2 or 5 of its power of ten (8,300 -> 10,000; 1,200 -> 2,000). */
    public static function nice(int $peak): int
    {
        $power = 10 ** (int) floor(log10(max(1, $peak)));
        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $power >= $peak) {
                return $step * $power;
            }
        }

        return 10 * $power;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
