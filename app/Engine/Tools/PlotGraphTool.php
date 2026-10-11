<?php

namespace App\Engine\Tools;

use App\Engine\Maths\Expression;
use App\Engine\Maths\MathsException;
use App\Platform\Access\Principal;

/**
 * Plots a function or a data set as a Mermaid xychart. A formula is evaluated here.
 * A formula that cannot be read, and a call with no data, return an explanation and no chart.
 */
final class PlotGraphTool implements Tool
{
    public function name(): string
    {
        return 'plot_graph';
    }

    public function description(): string
    {
        return 'Plots a function graph or a data set with labeled axes. Pass a formula in x (or t) and a range, or explicit x and y values. Parameters go in variables. Trig uses radians unless angle is deg. Embed the returned mermaid block. A failed formula is reported and nothing is plotted.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Title of the graph.',
                ],
                'x_label' => [
                    'type' => 'string',
                    'description' => 'Horizontal axis label, with the unit when there is one (e.g. "x" or "t (s)").',
                ],
                'y_label' => [
                    'type' => 'string',
                    'description' => 'Vertical axis label, with the unit when there is one.',
                ],
                'chart_type' => [
                    'type' => 'string',
                    'enum' => ['line', 'bar'],
                    'description' => 'line (the default) or bar.',
                ],
                'formula' => [
                    'type' => 'string',
                    'description' => 'Expression in x or t, such as "sin(x)", "a*x^2 + b*x + c" or "exp(-t)".',
                ],
                'variables' => [
                    'type' => 'object',
                    'description' => 'Named numbers used by the formula, such as {"a": 1, "b": -2}. x and t are the axis.',
                    'additionalProperties' => ['type' => 'number'],
                ],
                'angle' => [
                    'type' => 'string',
                    'enum' => ['rad', 'deg'],
                    'description' => 'Angle unit for the formula. Radians when left out.',
                ],
                'x_min' => [
                    'type' => 'number',
                    'description' => 'Left end of a formula plot. 0 when left out.',
                ],
                'x_max' => [
                    'type' => 'number',
                    'description' => 'Right end of a formula plot. 10 when left out.',
                ],
                'points_count' => [
                    'type' => 'integer',
                    'description' => 'How many samples, from 3 to 201. 61 when left out.',
                ],
                'x_values' => [
                    'type' => 'array',
                    'items' => ['type' => ['number', 'string']],
                    'description' => 'Horizontal values for a data plot. Required with y_values or series unless the positions are 0, 1, 2, …',
                ],
                'y_values' => [
                    'type' => 'array',
                    'items' => ['type' => 'number'],
                    'description' => 'Vertical values, one per x value.',
                ],
                'series' => [
                    'type' => 'array',
                    'description' => 'Several curves on the same axes.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Name of this curve.'],
                            'type' => ['type' => 'string', 'enum' => ['line', 'bar']],
                            'values' => ['type' => 'array', 'items' => ['type' => 'number']],
                        ],
                        'required' => ['name', 'values'],
                    ],
                ],
            ],
            'required' => ['title', 'x_label', 'y_label'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        try {
            $title = self::cleanLabel(trim((string) ($input['title'] ?? '')) !== '' ? trim((string) $input['title']) : 'Graph');
            $xLabel = self::cleanLabel(trim((string) ($input['x_label'] ?? '')) !== '' ? trim((string) $input['x_label']) : 'x');
            $yLabel = self::cleanLabel(trim((string) ($input['y_label'] ?? '')) !== '' ? trim((string) $input['y_label']) : 'y');
            $requested = $input['chart_type'] ?? 'line';
            $chartType = in_array($requested, ['line', 'bar'], true) ? $requested : 'line';
            $formula = isset($input['formula']) && is_string($input['formula']) ? trim($input['formula']) : '';

            if ($formula !== '') {
                [$xValues, $dataSeries] = $this->fromFormula($formula, $input, $chartType);
            } elseif (! empty($input['series']) && is_array($input['series'])) {
                [$xValues, $dataSeries] = $this->fromSeries($input, $chartType);
            } else {
                [$xValues, $dataSeries] = $this->fromPoints($input, $yLabel, $chartType);
            }
        } catch (MathsException $e) {
            return 'Could not plot: '.$e->getMessage();
        }

        $allY = [];
        foreach ($dataSeries as $series) {
            foreach ($series['values'] as $value) {
                $allY[] = $value;
            }
        }
        $minY = min($allY);
        $maxY = max($allY);
        if ($minY === $maxY) {
            $maxY = $minY + 1;
        }

        $numericX = count($xValues) > 12 && array_reduce($xValues, fn (bool $numeric, mixed $value) => $numeric && is_numeric($value), true);
        $xAxis = $numericX
            ? 'x-axis "'.$xLabel.'" '.Expression::format((float) $xValues[0]).' --> '.Expression::format((float) $xValues[array_key_last($xValues)])
            : 'x-axis "'.$xLabel.'" ['.implode(', ', array_map(fn ($value) => is_float($value) || is_int($value) ? Expression::format((float) $value) : $value, $xValues)).']';

        $lines = ['```mermaid', 'xychart-beta', "    title \"{$title}\"", "    {$xAxis}", '    y-axis "'.$yLabel.'" '.Expression::format($minY).' --> '.Expression::format($maxY)];
        foreach ($dataSeries as $series) {
            $shown = array_map(fn (float $value) => Expression::format($value), $series['values']);
            $lines[] = "    {$series['type']} [".implode(', ', $shown).']';
        }
        $lines[] = '```';

        $showX = fn (mixed $value) => is_int($value) || is_float($value) ? Expression::format((float) $value) : (string) $value;
        $summary = ["### Plot generated: \"{$title}\""];
        $summary[] = "- Horizontal axis ({$xLabel}): ".$showX($xValues[0]).' to '.$showX($xValues[array_key_last($xValues)]);
        $summary[] = "- Vertical axis ({$yLabel}): ".Expression::format($minY).' to '.Expression::format($maxY);
        foreach ($dataSeries as $series) {
            $peak = max($series['values']);
            $trough = min($series['values']);
            $peakAt = $xValues[array_search($peak, $series['values'], true)] ?? 'n/a';
            $troughAt = $xValues[array_search($trough, $series['values'], true)] ?? 'n/a';
            $peakWhere = is_float($peakAt) || is_int($peakAt) ? Expression::format((float) $peakAt) : $peakAt;
            $troughWhere = is_float($troughAt) || is_int($troughAt) ? Expression::format((float) $troughAt) : $troughAt;
            $summary[] = '- Series "'.$series['name'].'": Peak = '.Expression::format($peak).' at x = '.$peakWhere.'; Minimum = '.Expression::format($trough).' at x = '.$troughWhere;
        }
        $summary[] = "\n```mermaid block to embed into response:\n".implode("\n", $lines);

        return implode("\n", $summary);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: list<float>, 1: list<array{name: string, type: string, values: list<float>}>}
     */
    private function fromFormula(string $formula, array $input, string $chartType): array
    {
        $maths = new Expression($formula);
        $variables = Expression::bindings($input['variables'] ?? []);
        $angle = Expression::angle($input['angle'] ?? 'rad');
        $xMin = (float) ($input['x_min'] ?? 0.0);
        $xMax = (float) ($input['x_max'] ?? 10.0);
        if ($xMax === $xMin) {
            throw new MathsException('The horizontal range has no width.');
        }
        if ($xMax < $xMin) {
            [$xMin, $xMax] = [$xMax, $xMin];
        }
        $count = max(3, min(201, (int) ($input['points_count'] ?? 61)));
        $step = ($xMax - $xMin) / ($count - 1);
        $xValues = [];
        $yValues = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $xMin + $i * $step;
            $scope = $variables;
            $scope['x'] = $x;
            if (! array_key_exists('t', $variables)) {
                $scope['t'] = $x;
            }
            try {
                $y = $maths->value($scope, $angle);
            } catch (MathsException $e) {
                throw new MathsException('At x = '.Expression::format($x).', '.$e->getMessage().' Narrow the range or change the expression.');
            }
            $xValues[] = Expression::finite($x, 'A sample on the horizontal axis is not finite.');
            $yValues[] = $y;
        }

        return [$xValues, [['name' => $formula, 'type' => $chartType, 'values' => $yValues]]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: list<float|string>, 1: list<array{name: string, type: string, values: list<float>}>}
     */
    private function fromSeries(array $input, string $chartType): array
    {
        $dataSeries = [];
        foreach ($input['series'] as $series) {
            if (! is_array($series) || ! isset($series['values']) || ! is_array($series['values']) || $series['values'] === []) {
                throw new MathsException('Each series needs a list of numbers.');
            }
            $type = in_array($series['type'] ?? $chartType, ['line', 'bar'], true) ? ($series['type'] ?? $chartType) : $chartType;
            $dataSeries[] = [
                'name' => self::cleanLabel(trim((string) ($series['name'] ?? 'Series'))),
                'type' => $type,
                'values' => array_map(fn ($value) => $this->number($value), $series['values']),
            ];
        }
        $lengths = array_unique(array_map(fn (array $series) => count($series['values']), $dataSeries));
        if (count($lengths) !== 1) {
            throw new MathsException('Every series needs the same number of values.');
        }
        $xValues = $this->horizontal($input['x_values'] ?? [], $lengths[array_key_first($lengths)]);

        return [$xValues, $dataSeries];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: list<float|string>, 1: list<array{name: string, type: string, values: list<float>}>}
     */
    private function fromPoints(array $input, string $yLabel, string $chartType): array
    {
        $rawY = $input['y_values'] ?? [];
        if (! is_array($rawY) || $rawY === []) {
            throw new MathsException('Give a formula, or the horizontal and vertical values. Nothing was plotted.');
        }
        $yValues = array_map(fn ($value) => $this->number($value), $rawY);

        return [$this->horizontal($input['x_values'] ?? [], count($yValues)), [['name' => $yLabel, 'type' => $chartType, 'values' => $yValues]]];
    }

    /**
     * @return list<float|string>
     */
    private function horizontal(mixed $rawX, int $count): array
    {
        if (! is_array($rawX) || $rawX === []) {
            return array_map(fn (int $index) => (float) $index, range(0, $count - 1));
        }
        if (count($rawX) !== $count) {
            throw new MathsException('The horizontal values and the vertical values must be the same length.');
        }
        $xValues = [];
        foreach ($rawX as $value) {
            if (is_string($value) && ! is_numeric($value)) {
                $xValues[] = self::cleanLabel($value);
            } else {
                $xValues[] = $this->number($value);
            }
        }

        return $xValues;
    }

    private function number(mixed $value): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value += 0;
        }
        if (! is_int($value) && ! is_float($value)) {
            throw new MathsException('A plotted value must be a number.');
        }

        return Expression::finite((float) $value, 'A plotted value is not a finite number.');
    }

    private static function cleanLabel(string $label): string
    {
        return str_replace(['"', "\n", "\r"], ["'", ' ', ''], trim($label));
    }
}
