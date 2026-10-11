<?php

namespace Tests\Unit\Engine;

use App\Engine\Tools\Context;
use App\Engine\Tools\PlotGraphTool;
use App\Platform\Access\Principal;
use Tests\TestCase;

class PlotGraphToolTest extends TestCase
{
    private PlotGraphTool $tool;

    private Context $context;

    private Principal $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new PlotGraphTool;
        $this->context = new Context('w1', 'm1', 's1', 'UTC');
        $this->principal = Principal::system();
    }

    public function test_tool_metadata(): void
    {
        $this->assertSame('plot_graph', $this->tool->name());
        $this->assertStringContainsString('function graph', $this->tool->description());

        $params = $this->tool->parameters();
        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('title', $params['properties']);
        $this->assertArrayHasKey('x_label', $params['properties']);
        $this->assertArrayHasKey('y_label', $params['properties']);
        $this->assertArrayHasKey('formula', $params['properties']);
    }

    public function test_plots_explicit_coordinates(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Velocity vs Time',
            'x_label' => 'Time t (s)',
            'y_label' => 'Velocity v (m/s)',
            'x_values' => [0, 1, 2, 3, 4],
            'y_values' => [0, 9.8, 19.6, 29.4, 39.2],
        ]);

        $this->assertStringContainsString('```mermaid', $result);
        $this->assertStringContainsString('xychart-beta', $result);
        $this->assertStringContainsString('title "Velocity vs Time"', $result);
        $this->assertStringContainsString('x-axis "Time t (s)" [0, 1, 2, 3, 4]', $result);
        $this->assertStringContainsString('y-axis "Velocity v (m/s)" 0 --> 39.2', $result);
        $this->assertStringContainsString('line [0, 9.8, 19.6, 29.4, 39.2]', $result);
        $this->assertStringContainsString('Peak = 39.2', $result);
    }

    public function test_evaluates_and_plots_mathematical_formula(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Quadratic Function',
            'x_label' => 'x',
            'y_label' => 'f(x)',
            'formula' => 'x^2 - 4*x + 3',
            'x_min' => 0,
            'x_max' => 4,
            'points_count' => 5,
        ]);

        $this->assertStringContainsString('```mermaid', $result);
        $this->assertStringContainsString('xychart-beta', $result);
        $this->assertStringContainsString('title "Quadratic Function"', $result);
        $this->assertStringContainsString('x-axis "x" [0, 1, 2, 3, 4]', $result);
        // x=0 -> 3, x=1 -> 0, x=2 -> -1, x=3 -> 0, x=4 -> 3
        $this->assertStringContainsString('line [3, 0, -1, 0, 3]', $result);
        $this->assertStringContainsString('Minimum = -1', $result);
    }

    public function test_plots_kinematics_projectile_motion(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Trajectory: Height vs Time',
            'x_label' => 'Time (s)',
            'y_label' => 'Height (m)',
            'formula' => '-5*t^2 + 20*t',
            'x_min' => 0,
            'x_max' => 4,
            'points_count' => 5,
        ]);

        $this->assertStringContainsString('```mermaid', $result);
        $this->assertStringContainsString('xychart-beta', $result);
        // t=0 -> 0, t=1 -> 15, t=2 -> 20, t=3 -> 15, t=4 -> 0
        $this->assertStringContainsString('line [0, 15, 20, 15, 0]', $result);
        $this->assertStringContainsString('Peak = 20 at x = 2', $result);
    }

    public function test_plots_multiple_series(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Energy Conservation',
            'x_label' => 'Position (m)',
            'y_label' => 'Energy (J)',
            'x_values' => [0, 2, 4, 6],
            'series' => [
                ['name' => 'Kinetic Energy', 'type' => 'line', 'values' => [100, 75, 25, 0]],
                ['name' => 'Potential Energy', 'type' => 'line', 'values' => [0, 25, 75, 100]],
            ],
        ]);

        $this->assertStringContainsString('```mermaid', $result);
        $this->assertStringContainsString('line [100, 75, 25, 0]', $result);
        $this->assertStringContainsString('line [0, 25, 75, 100]', $result);
    }

    public function test_a_formula_with_parameters_uses_the_given_numbers(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Scaled parabola',
            'x_label' => 'x',
            'y_label' => 'y',
            'formula' => 'a*x^2 + b',
            'variables' => ['a' => 2, 'b' => -1],
            'x_min' => 0,
            'x_max' => 2,
            'points_count' => 3,
        ]);

        $this->assertStringContainsString('line [-1, 1, 7]', $result);
    }

    public function test_a_smooth_plot_uses_a_numeric_axis_and_does_not_invent_a_line(): void
    {
        $result = $this->tool->run($this->principal, $this->context, [
            'title' => 'Square',
            'x_label' => 'x',
            'y_label' => 'x squared',
            'formula' => 'x^2',
            'x_min' => 0,
            'x_max' => 2,
        ]);

        $this->assertStringContainsString('x-axis "x" 0 --> 2', $result);
        $this->assertStringContainsString('line [0, ', $result);
        $this->assertStringNotContainsString('line [0, 2, 4, 6, 8, 10]', $result);
        $this->assertGreaterThan(20, substr_count($result, ','));
    }

    public function test_a_missing_or_invalid_formula_is_not_plotted(): void
    {
        $missing = $this->tool->run($this->principal, $this->context, [
            'title' => 'Empty',
            'x_label' => 'x',
            'y_label' => 'y',
        ]);
        $this->assertStringStartsWith('Could not plot:', $missing);
        $this->assertStringNotContainsString('```mermaid', $missing);
        $this->assertStringNotContainsString('line [0, 2, 4, 6, 8, 10]', $missing);

        $invalid = $this->tool->run($this->principal, $this->context, [
            'title' => 'Invalid',
            'x_label' => 'x',
            'y_label' => 'y',
            'formula' => 'sqrt(-1)',
            'x_min' => 0,
            'x_max' => 1,
            'points_count' => 5,
        ]);
        $this->assertStringStartsWith('Could not plot:', $invalid);
        $this->assertStringNotContainsString('```mermaid', $invalid);
    }
}
