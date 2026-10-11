<?php

namespace Tests\Unit\Engine;

use App\Engine\Maths\Expression;
use App\Engine\Maths\MathsException;
use App\Engine\Tools\CalculateTool;
use App\Engine\Tools\Context;
use App\Platform\Access\Principal;
use Tests\TestCase;

class CalculateToolTest extends TestCase
{
    private CalculateTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CalculateTool;
    }

    public function test_evaluates_ordinary_and_higher_expressions(): void
    {
        $maths = new Expression('sin(pi/2) + log10(100) + ncr(5, 2) + 5!');
        $this->assertSame(133.0, $maths->value());
        $this->assertSame(0.001, (new Expression('1e-3'))->value());
        $this->assertSame(6.0, (new Expression('2x'))->value(['x' => 3]));
        $this->assertSame(1.0, (new Expression('sin(90)'))->value([], 'deg'));
        $this->assertEqualsWithDelta(1 / 3, (new Expression('x^2'))->integral('x', 0, 1, []), 1e-8);
        $this->assertSame(6.0, (new Expression('x^2'))->derivative('x', 3, []));
        $roots = (new Expression('x^2 - 2'))->solve('x', 0, 2, []);
        $this->assertCount(1, $roots);
        $this->assertEqualsWithDelta(sqrt(2), $roots[0], 1e-6);
    }

    public function test_a_bad_expression_is_refused(): void
    {
        $this->expectException(MathsException::class);
        (new Expression('sqrt(-1) + unknown'))->value();
    }

    public function test_the_tool_quotes_the_computed_value_and_reports_a_failure(): void
    {
        $context = new Context('w1', 'm1', 's1', 'UTC');
        $by = Principal::system();

        $value = $this->tool->run($by, $context, [
            'expression' => 'a*x^2 + b',
            'variables' => ['a' => 3, 'x' => 0, 'b' => 1],
            'operation' => 'evaluate',
        ]);
        $this->assertStringContainsString('value: 1', $value);

        $derivative = $this->tool->run($by, $context, [
            'expression' => 'x^3',
            'operation' => 'derivative',
            'at' => 2,
        ]);
        $this->assertStringContainsString('value: 12', $derivative);

        $failed = $this->tool->run($by, $context, ['expression' => '1/0']);
        $this->assertStringStartsWith('Could not calculate:', $failed);
        $this->assertStringNotContainsString('value:', $failed);
    }
}
