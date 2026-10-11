<?php

namespace App\Engine\Maths;

/**
 * A real-valued expression. It evaluates numbers, derivatives, definite integrals and roots.
 * It does not simplify or prove anything, and it never invents a value for an expression it cannot read.
 */
final class Expression
{
    private const FUNCTIONS = [
        'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2',
        'sinh', 'cosh', 'tanh', 'asinh', 'acosh', 'atanh',
        'sqrt', 'cbrt', 'abs', 'sign', 'floor', 'ceil', 'round', 'exp',
        'ln', 'log', 'log10', 'log2', 'deg', 'rad', 'factorial', 'gamma',
        'ncr', 'npr', 'hypot', 'min', 'max',
    ];

    /** @var list<string> */
    private array $tokens = [];

    private int $pos = 0;

    /** @var array<string, float> */
    private array $variables = [];

    private string $angle = 'rad';

    public function __construct(private string $source) {}

    /** @param array<string, float|int> $variables @param 'rad'|'deg' $angle */
    public function value(array $variables = [], string $angle = 'rad'): float
    {
        $this->prepare($variables, $angle);
        $value = $this->parseAdd();
        if ($this->pos < count($this->tokens)) {
            throw new MathsException('Unexpected "'.$this->tokens[$this->pos].'" in the expression.');
        }

        return self::finite($value, 'The result is not a finite number.');
    }

    /**
     * The derivative with respect to $variable at $at, by a central difference.
     *
     * @param  array<string, float|int>  $variables
     * @param  'rad'|'deg'  $angle
     */
    public function derivative(string $variable, float $at, array $variables = [], string $angle = 'rad'): float
    {
        $this->name($variable);
        $scale = max(1.0, abs($at));
        $last = null;
        foreach ([1e-4, 1e-6, 1e-7] as $factor) {
            $step = $factor * $scale;
            try {
                $high = $this->value([$variable => $at + $step] + $variables, $angle);
                $low = $this->value([$variable => $at - $step] + $variables, $angle);
            } catch (MathsException $e) {
                $last = $e;

                continue;
            }
            $slope = ($high - $low) / (2 * $step);
            if (is_finite($slope)) {
                return self::finite($slope, 'The derivative is not a finite number.');
            }
        }

        throw $last ?? new MathsException('The derivative is not a finite number at '.self::format($at).'.');
    }

    /**
     * The definite integral of the expression in $variable from $from to $to (Simpson's rule).
     *
     * @param  array<string, float|int>  $variables
     * @param  'rad'|'deg'  $angle
     */
    public function integral(string $variable, float $from, float $to, array $variables = [], string $angle = 'rad'): float
    {
        $this->name($variable);
        if ($from === $to) {
            return 0.0;
        }
        $steps = 400;
        $width = ($to - $from) / $steps;
        $sum = $this->value([$variable => $from] + $variables, $angle) + $this->value([$variable => $to] + $variables, $angle);
        for ($i = 1; $i < $steps; $i++) {
            $sample = $this->value([$variable => $from + $i * $width] + $variables, $angle);
            $sum += ($i % 2 === 0 ? 2 : 4) * $sample;
        }

        return self::finite($sum * $width / 3, 'The integral is not a finite number on that interval.');
    }

    /**
     * Roots of the expression in $variable on [$from, $to], up to eight of them.
     *
     * @param  array<string, float|int>  $variables
     * @param  'rad'|'deg'  $angle
     * @return list<float>
     */
    public function solve(string $variable, float $from, float $to, array $variables = [], string $angle = 'rad'): array
    {
        $this->name($variable);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $panels = 64;
        $step = ($to - $from) / $panels;
        $roots = [];
        $previousX = $from;
        $previousY = $this->value([$variable => $from] + $variables, $angle);
        if (abs($previousY) <= 1e-9) {
            $roots[] = $from;
        }
        for ($i = 1; $i <= $panels; $i++) {
            $x = $i === $panels ? $to : $from + $i * $step;
            $y = $this->value([$variable => $x] + $variables, $angle);
            if (abs($y) <= 1e-9) {
                $roots[] = $x;
            } elseif ($previousY * $y < 0) {
                $roots[] = $this->bisect($variable, $previousX, $x, $variables, $angle);
            }
            $previousX = $x;
            $previousY = $y;
            if (count($roots) >= 8) {
                break;
            }
        }
        $unique = [];
        foreach ($roots as $root) {
            foreach ($unique as $kept) {
                if (abs($root - $kept) <= 1e-6 * max(1.0, abs($kept))) {
                    continue 2;
                }
            }
            $unique[] = self::finite($root, 'A root is not a finite number.');
        }
        if ($unique === []) {
            throw new MathsException('No root on ['.self::format($from).', '.self::format($to).'].');
        }

        return $unique;
    }

    /**
     * @param  array<mixed>  $raw  a map of names to numbers, or a JSON object
     * @return array<string, float>
     */
    public static function bindings(mixed $raw): array
    {
        if ($raw === null || $raw === [] || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new MathsException('Variables must be a map of names to numbers.');
            }
            $raw = $decoded;
        }
        if (! is_array($raw)) {
            throw new MathsException('Variables must be a map of names to numbers.');
        }
        $bindings = [];
        foreach ($raw as $name => $value) {
            if (! is_string($name)) {
                throw new MathsException('A variable name must be letters, digits and underscores.');
            }
            self::name($name);
            if (is_string($value) && is_numeric($value)) {
                $value += 0;
            }
            if (! is_int($value) && ! is_float($value)) {
                throw new MathsException("Variable {$name} must be a number.");
            }
            $bindings[$name] = (float) $value;
        }

        return $bindings;
    }

    /** @param 'rad'|'deg' $angle */
    public static function angle(mixed $angle): string
    {
        $angle = is_string($angle) && $angle !== '' ? $angle : 'rad';
        if (! in_array($angle, ['rad', 'deg'], true)) {
            throw new MathsException('Angle unit must be rad or deg.');
        }

        return $angle;
    }

    public static function format(float $number): string
    {
        $number = self::finite($number, 'The result is not a finite number.');
        if (abs($number) >= 1e8 || (abs($number) > 0 && abs($number) < 1e-6)) {
            return sprintf('%.6e', $number);
        }
        if (abs($number - round($number)) < 1e-8) {
            return sprintf('%.0F', $number);
        }

        return rtrim(rtrim(sprintf('%.8F', $number), '0'), '.');
    }

    public static function finite(float $number, string $message): float
    {
        if (! is_finite($number)) {
            throw new MathsException($message);
        }
        $rounded = round($number, 10);

        return abs($rounded - round($rounded)) < 1e-9 ? (float) round($rounded) : $rounded;
    }

    /** @param array<string, float|int> $variables @param 'rad'|'deg' $angle */
    private function prepare(array $variables, string $angle): void
    {
        $source = trim($this->source);
        if ($source === '') {
            throw new MathsException('The expression is empty.');
        }
        if (strlen($source) > 2000) {
            throw new MathsException('The expression is too long.');
        }
        $this->angle = self::angle($angle);
        $this->variables = [];
        foreach ($variables as $name => $value) {
            self::name((string) $name);
            $this->variables[(string) $name] = (float) $value;
        }
        $this->tokens = $this->tokenize($source);
        $this->pos = 0;
        if ($this->tokens === []) {
            throw new MathsException('The expression is empty.');
        }
    }

    /** @return list<string> */
    private function tokenize(string $expr): array
    {
        $expr = str_replace(['**', 'π', '×', '÷', '−'], ['^', 'pi', '*', '/', '-'], $expr);
        $expr = (string) preg_replace('/\s+/', '', $expr);
        $tokens = [];
        $length = strlen($expr);
        for ($i = 0; $i < $length;) {
            $char = $expr[$i];
            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($expr[$i + 1]))) {
                if (preg_match('/\G(?:\d+\.\d+|\d+|\.\d+)(?:e[+-]?\d+)?/i', $expr, $match, 0, $i) !== 1) {
                    throw new MathsException('Cannot read a number in the expression.');
                }
                $tokens[] = ['n', $match[0]];
                $i += strlen($match[0]);

                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $expr, $match, 0, $i) !== 1) {
                    throw new MathsException('Cannot read a name in the expression.');
                }
                $tokens[] = ['id', $match[0]];
                $i += strlen($match[0]);

                continue;
            }
            if (in_array($char, ['+', '-', '*', '/', '^', '%', '(', ')', '!', ','], true)) {
                $tokens[] = ['op', $char];
                $i++;

                continue;
            }
            throw new MathsException("Cannot read \"{$char}\" in the expression.");
        }

        $withProducts = [];
        foreach ($tokens as $index => $token) {
            if ($index > 0 && self::endsAtom($tokens[$index - 1]) && self::startsAtom($token, $tokens[$index - 1])) {
                $withProducts[] = ['op', '*'];
            }
            $withProducts[] = $token;
        }

        return array_map(fn (array $token) => $token[0] === 'n' ? $token[1] : ($token[0] === 'id' ? $token[1] : $token[1]), $withProducts);
    }

    /** @param array{0: string, 1: string} $token */
    private static function endsAtom(array $token): bool
    {
        return $token[0] === 'n' || $token[0] === 'id' || $token[1] === ')' || $token[1] === '!';
    }

    /**
     * An identifier followed by "(" is a function call, not a product, when the name is a function.
     *
     * @param  array{0: string, 1: string}  $token
     * @param  array{0: string, 1: string}  $previous
     */
    private static function startsAtom(array $token, array $previous): bool
    {
        if ($previous[0] === 'id' && $token[1] === '(' && in_array(strtolower($previous[1]), self::FUNCTIONS, true)) {
            return false;
        }

        return $token[0] === 'n' || $token[0] === 'id' || $token[1] === '(';
    }

    private function parseAdd(): float
    {
        $value = $this->parseMul();
        while ($this->is('+') || $this->is('-')) {
            $op = $this->tokens[$this->pos++];
            $right = $this->parseMul();
            $value = $op === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseMul(): float
    {
        $value = $this->parsePow();
        while ($this->is('*') || $this->is('/') || $this->is('%')) {
            $op = $this->tokens[$this->pos++];
            $right = $this->parsePow();
            $value = match ($op) {
                '*' => $value * $right,
                '/' => $right == 0.0 ? throw new MathsException('Division by zero.') : $value / $right,
                default => $right == 0.0 ? throw new MathsException('Division by zero.') : fmod($value, $right),
            };
        }

        return $value;
    }

    private function parsePow(): float
    {
        $value = $this->parseUnary();
        if ($this->is('^')) {
            $this->pos++;
            $value = $value ** $this->parsePow();
        }

        return $value;
    }

    private function parseUnary(): float
    {
        if ($this->is('-')) {
            $this->pos++;

            return -$this->parseUnary();
        }
        if ($this->is('+')) {
            $this->pos++;

            return $this->parseUnary();
        }

        return $this->parsePostfix();
    }

    private function parsePostfix(): float
    {
        $value = $this->parsePrimary();
        while ($this->is('!')) {
            $this->pos++;
            $value = $this->factorial($value);
        }

        return $value;
    }

    private function parsePrimary(): float
    {
        if ($this->pos >= count($this->tokens)) {
            throw new MathsException('The expression ended early.');
        }
        $token = $this->tokens[$this->pos++];
        if (is_numeric($token)) {
            return (float) $token;
        }
        if ($token === '(') {
            $value = $this->parseAdd();
            if (! $this->is(')')) {
                throw new MathsException('A parenthesis is missing.');
            }
            $this->pos++;

            return $value;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token) === 1) {
            $name = strtolower($token);
            if ($this->is('(') && in_array($name, self::FUNCTIONS, true)) {
                return $this->call($name, $this->arguments());
            }
            if (array_key_exists($token, $this->variables)) {
                return $this->variables[$token];
            }
            if (array_key_exists($name, $this->variables)) {
                return $this->variables[$name];
            }

            return match ($name) {
                'pi' => M_PI,
                'e' => M_E,
                'tau' => 2 * M_PI,
                default => throw new MathsException("Unknown name \"{$token}\". Give it in variables, or use a known function."),
            };
        }
        throw new MathsException("Unexpected \"{$token}\" in the expression.");
    }

    /** @return list<float> */
    private function arguments(): array
    {
        $this->pos++;
        if ($this->is(')')) {
            $this->pos++;

            return [];
        }
        $args = [];
        do {
            $args[] = $this->parseAdd();
        } while ($this->eat(','));
        if (! $this->is(')')) {
            throw new MathsException('A parenthesis is missing.');
        }
        $this->pos++;

        return $args;
    }

    /** @param list<float> $args */
    private function call(string $name, array $args): float
    {
        $need = match ($name) {
            'atan2', 'ncr', 'npr', 'hypot', 'log' => null,
            'min', 'max' => null,
            default => 1,
        };
        if ($need === 1 && count($args) !== 1) {
            throw new MathsException("{$name} takes one argument.");
        }
        if (in_array($name, ['atan2', 'ncr', 'npr', 'hypot'], true) && count($args) !== 2) {
            throw new MathsException("{$name} takes two arguments.");
        }
        if ($name === 'log' && ! in_array(count($args), [1, 2], true)) {
            throw new MathsException('log takes a value, or a value and a base.');
        }
        if (in_array($name, ['min', 'max'], true) && $args === []) {
            throw new MathsException("{$name} takes at least one argument.");
        }
        $a = $args[0] ?? 0.0;

        return match ($name) {
            'sin' => sin($this->toRadians($a)),
            'cos' => cos($this->toRadians($a)),
            'tan' => tan($this->toRadians($a)),
            'asin' => $this->fromRadians(asin(self::domain($a, -1, 1, 'asin'))),
            'acos' => $this->fromRadians(acos(self::domain($a, -1, 1, 'acos'))),
            'atan' => $this->fromRadians(atan($a)),
            'atan2' => $this->fromRadians(atan2($args[0], $args[1])),
            'sinh' => sinh($a),
            'cosh' => cosh($a),
            'tanh' => tanh($a),
            'asinh' => asinh($a),
            'acosh' => $a < 1 ? throw new MathsException('acosh is defined for arguments of at least 1.') : acosh($a),
            'atanh' => abs($a) >= 1 ? throw new MathsException('atanh is defined strictly between -1 and 1.') : atanh($a),
            'sqrt' => $a < 0 ? throw new MathsException('sqrt is not defined for a negative number.') : sqrt($a),
            'cbrt' => $a < 0 ? -((-$a) ** (1 / 3)) : $a ** (1 / 3),
            'abs' => abs($a),
            'sign' => $a < 0 ? -1.0 : ($a > 0 ? 1.0 : 0.0),
            'floor' => floor($a),
            'ceil' => ceil($a),
            'round' => round($a),
            'exp' => exp($a),
            'ln', 'log' => count($args) === 1 ? $this->logarithm($a, M_E) : $this->logarithm($args[0], $args[1]),
            'log10' => $this->logarithm($a, 10),
            'log2' => $this->logarithm($a, 2),
            'deg' => rad2deg($a),
            'rad' => deg2rad($a),
            'factorial' => $this->factorial($a),
            'gamma' => $this->gamma($a),
            'ncr' => $this->choose($args[0], $args[1], false),
            'npr' => $this->choose($args[0], $args[1], true),
            'hypot' => hypot($args[0], $args[1]),
            'min' => min($args),
            'max' => max($args),
            default => throw new MathsException("Unknown function \"{$name}\"."),
        };
    }

    private function toRadians(float $angle): float
    {
        return $this->angle === 'deg' ? deg2rad($angle) : $angle;
    }

    private function fromRadians(float $angle): float
    {
        return $this->angle === 'deg' ? rad2deg($angle) : $angle;
    }

    private static function domain(float $value, float $low, float $high, string $name): float
    {
        if ($value < $low || $value > $high) {
            throw new MathsException("{$name} is not defined for that argument.");
        }

        return $value;
    }

    private function logarithm(float $value, float $base): float
    {
        if ($value <= 0) {
            throw new MathsException('A logarithm is defined for positive numbers only.');
        }
        if ($base <= 0 || $base === 1.0) {
            throw new MathsException('A logarithm base must be positive and not 1.');
        }

        return log($value) / log($base);
    }

    private function factorial(float $n): float
    {
        if ($n < 0) {
            throw new MathsException('factorial is not defined for a negative number.');
        }
        if (abs($n - round($n)) < 1e-9) {
            $k = (int) round($n);
            if ($k > 170) {
                throw new MathsException('factorial is too large.');
            }
            $product = 1.0;
            for ($i = 2; $i <= $k; $i++) {
                $product *= $i;
            }

            return $product;
        }

        return $this->gamma($n + 1);
    }

    /** Lanczos approximation, with the reflection formula below 0.5. */
    private function gamma(float $z): float
    {
        if ($z <= 0 && abs($z - round($z)) < 1e-9) {
            throw new MathsException('gamma is not defined at zero or a negative integer.');
        }
        if ($z < 0.5) {
            $reflected = M_PI / sin(M_PI * $z);

            return self::finite($reflected / $this->gamma(1 - $z), 'gamma is not a finite number.');
        }
        $coefficients = [
            0.99999999999980993,
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];
        $z -= 1;
        $sum = $coefficients[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += $coefficients[$i] / ($z + $i);
        }
        $t = $z + 7.5;

        return self::finite(sqrt(2 * M_PI) * ($t ** ($z + 0.5)) * exp(-$t) * $sum, 'gamma is not a finite number.');
    }

    private function choose(float $n, float $k, bool $permutation): float
    {
        if ($n < 0 || $k < 0 || abs($n - round($n)) > 1e-9 || abs($k - round($k)) > 1e-9) {
            throw new MathsException(($permutation ? 'npr' : 'ncr').' expects non-negative integers.');
        }
        $n = (int) round($n);
        $k = (int) round($k);
        if ($k > $n) {
            throw new MathsException(($permutation ? 'npr' : 'ncr').' expects the second argument to be at most the first.');
        }
        if ($n > 10000) {
            throw new MathsException('That combination is too large to compute.');
        }
        $take = $permutation ? $k : min($k, $n - $k);
        $product = 1.0;
        for ($i = 1; $i <= $take; $i++) {
            $product = $permutation ? $product * ($n - $k + $i) : $product * ($n - $take + $i) / $i;
        }

        return $product;
    }

    /** @param array<string, float|int> $variables @param 'rad'|'deg' $angle */
    private function bisect(string $variable, float $low, float $high, array $variables, string $angle): float
    {
        $left = $this->value([$variable => $low] + $variables, $angle);
        for ($i = 0; $i < 80; $i++) {
            $mid = ($low + $high) / 2;
            $value = $this->value([$variable => $mid] + $variables, $angle);
            if (abs($value) <= 1e-10 || abs($high - $low) <= 1e-10) {
                return $mid;
            }
            if ($left * $value <= 0) {
                $high = $mid;
            } else {
                $low = $mid;
                $left = $value;
            }
        }

        return ($low + $high) / 2;
    }

    private function is(string $op): bool
    {
        return ($this->tokens[$this->pos] ?? null) === $op;
    }

    private function eat(string $op): bool
    {
        if (! $this->is($op)) {
            return false;
        }
        $this->pos++;

        return true;
    }

    private static function name(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new MathsException('A variable name must be letters, digits and underscores.');
        }
    }
}
