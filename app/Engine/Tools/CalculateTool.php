<?php

namespace App\Engine\Tools;

use App\Engine\Maths\Expression;
use App\Engine\Maths\MathsException;
use App\Platform\Access\Principal;

/**
 * Computes a real number from an expression: its value, a derivative, a definite integral, or roots.
 * The tutor quotes this result instead of guessing one. It does not simplify or prove the expression.
 */
final class CalculateTool implements Tool
{
    public function name(): string
    {
        return 'calculate';
    }

    public function description(): string
    {
        return 'Computes a real number from a mathematical expression: its value, the derivative at a point, a definite integral, or the roots on an interval. Use it whenever the answer depends on a number, in any topic from arithmetic to analysis. log is the natural logarithm; log10 and log2 are the other bases; log(value, base) takes a base. Trig uses radians unless angle is deg. Names other than x, t and the constants pi, e and tau go in variables.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'description' => 'The expression. Examples: "sin(pi/2)", "a*x^2 + b*x + c", "ncr(10, 3)", "exp(-x^2/2)".',
                ],
                'variables' => [
                    'type' => 'object',
                    'description' => 'Named numbers the expression uses, such as {"a": 2, "b": -1}.',
                    'additionalProperties' => ['type' => 'number'],
                ],
                'angle' => [
                    'type' => 'string',
                    'enum' => ['rad', 'deg'],
                    'description' => 'Angle unit for sin, cos, tan and their inverses. Radians when left out.',
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['evaluate', 'derivative', 'integral', 'solve'],
                    'description' => 'evaluate (the default), derivative at "at", integral from "from" to "to", or solve for roots on that interval.',
                ],
                'variable' => [
                    'type' => 'string',
                    'description' => 'The variable for a derivative, integral or root. x when left out.',
                ],
                'at' => [
                    'type' => 'number',
                    'description' => 'Where to take the derivative.',
                ],
                'from' => [
                    'type' => 'number',
                    'description' => 'Start of the integral, or of the interval to search for roots.',
                ],
                'to' => [
                    'type' => 'number',
                    'description' => 'End of the integral, or of the interval to search for roots.',
                ],
            ],
            'required' => ['expression'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        try {
            $expression = trim((string) ($input['expression'] ?? ''));
            if ($expression === '') {
                return 'Could not calculate: the expression is empty.';
            }
            $maths = new Expression($expression);
            $variables = Expression::bindings($input['variables'] ?? []);
            $angle = Expression::angle($input['angle'] ?? 'rad');
            $operation = $input['operation'] ?? 'evaluate';
            if (! in_array($operation, ['evaluate', 'derivative', 'integral', 'solve'], true)) {
                return 'Could not calculate: operation must be evaluate, derivative, integral or solve.';
            }
            $variable = trim((string) ($input['variable'] ?? 'x'));
            if ($variable === '') {
                $variable = 'x';
            }
            $lines = ["expression: {$expression}", "operation: {$operation}", 'angle: '.($angle === 'deg' ? 'degrees' : 'radians')];
            if ($variables !== []) {
                $shown = [];
                foreach ($variables as $name => $value) {
                    $shown[] = $name.' = '.Expression::format($value);
                }
                $lines[] = 'variables: '.implode(', ', $shown);
            }

            if ($operation === 'evaluate') {
                $lines[] = 'value: '.Expression::format($maths->value($variables, $angle));
            } elseif ($operation === 'derivative') {
                if (! isset($input['at']) || ! is_numeric($input['at'])) {
                    return 'Could not calculate: a derivative needs "at", the point to differentiate at.';
                }
                $at = (float) $input['at'];
                $lines[] = "variable: {$variable}";
                $lines[] = 'at: '.Expression::format($at);
                $lines[] = 'value: '.Expression::format($maths->derivative($variable, $at, $variables, $angle));
            } else {
                if (! isset($input['from'], $input['to']) || ! is_numeric($input['from']) || ! is_numeric($input['to'])) {
                    return 'Could not calculate: an integral or a root needs "from" and "to".';
                }
                $from = (float) $input['from'];
                $to = (float) $input['to'];
                $lines[] = "variable: {$variable}";
                $lines[] = 'interval: ['.Expression::format($from).', '.Expression::format($to).']';
                if ($operation === 'integral') {
                    $lines[] = 'value: '.Expression::format($maths->integral($variable, $from, $to, $variables, $angle));
                } else {
                    $roots = array_map(Expression::format(...), $maths->solve($variable, $from, $to, $variables, $angle));
                    $lines[] = 'roots: '.implode(', ', $roots);
                }
            }

            return implode("\n", $lines);
        } catch (MathsException $e) {
            return 'Could not calculate: '.$e->getMessage();
        }
    }
}
