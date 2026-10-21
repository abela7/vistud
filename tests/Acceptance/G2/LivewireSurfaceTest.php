<?php

namespace Tests\Acceptance\G2;

use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * G2 over Livewire: every student component, mounted as A on B's and C's ids, then mounted on A's own records with
 * every public action called with B's ids. Nothing of B's or C's may be rendered, and nothing of theirs may change.
 */
class LivewireSurfaceTest extends TestCase
{
    use World;

    /** Livewire's own lifecycle, never an action a student calls. */
    private const LIFECYCLE = '/^(mount|render|boot|booted|hydrate|dehydrate|updating|updated|rendering|rendered|rules|messages|validationAttributes|placeholder|exception|getListeners)/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @return list<class-string<Component>> every component outside the admin area */
    private function components(): array
    {
        $found = [];
        foreach (Finder::create()->files()->in(app_path('Livewire'))->name('*.php')->notPath('Admin') as $file) {
            $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /** A value for a parameter by its name: $who's record of the kind the name says, or a harmless value. */
    private function value(\ReflectionParameter $param, string $who): mixed
    {
        $ids = $this->ids($who);
        $name = strtolower($param->getName());
        foreach (['workspace' => 'course', 'course' => 'course', 'module' => 'module', 'folder' => 'folder', 'note' => 'note', 'file' => 'file',
            'topic' => 'topic', 'question' => 'question', 'card' => 'card', 'flashcard' => 'card', 'memory' => 'memory', 'link' => 'link',
            'session' => 'session', 'activity' => 'assignment', 'assignment' => 'assignment', 'section' => 'section', 'class' => 'class',
            'conversation' => 'conversation', 'thread' => 'conversation', 'place' => 'module', 'item' => 'section', 'part' => 'section'] as $word => $kind) {
            if (str_contains($name, $word)) {
                return $ids[$kind];
            }
        }
        if ($name === 'id' || str_ends_with($name, 'id')) {
            return $ids['note'];
        }
        $type = $param->getType() instanceof ReflectionNamedType ? $param->getType()->getName() : 'string';

        return match ($type) {
            'int' => 1, 'bool' => true, 'array' => [], 'float' => 1.0,
            default => $param->isDefaultValueAvailable() && $param->getDefaultValue() !== null ? $param->getDefaultValue() : 'x',
        };
    }

    /** @return array<string, mixed>|null mount arguments for $class, or null when it takes something unknown */
    private function mountArguments(string $class, string $who): ?array
    {
        if (! method_exists($class, 'mount')) {
            return [];
        }
        $args = [];
        foreach ((new ReflectionMethod($class, 'mount'))->getParameters() as $param) {
            if ($param->getType() instanceof ReflectionNamedType && ! $param->getType()->isBuiltin()) {
                continue; // injected by the container
            }
            $args[$param->getName()] = $this->value($param, $who);
        }

        return $args;
    }

    public function test_every_component_mounted_as_a_on_bs_or_cs_records_shows_nothing_of_theirs(): void
    {
        $before = [$this->footprint('B'), $this->footprint('C')];
        $mounted = 0;
        foreach ($this->components() as $class) {
            foreach (['B', 'C'] as $other) {
                try {
                    $html = Livewire::actingAs($this->learners['A']['user'])->test($class, $this->mountArguments($class, $other))->html();
                    $mounted++;
                } catch (\Throwable $e) {
                    $html = $e->getMessage();
                }
                $this->assertNothingOfOthers($html, "{$class} mounted on {$other}'s ids");
            }
        }
        $this->assertGreaterThan(20, $mounted, 'components mounted');
        $this->assertSame($before, [$this->footprint('B'), $this->footprint('C')]);
    }

    public function test_every_action_on_a_s_own_components_called_with_bs_ids_returns_and_changes_nothing_of_bs(): void
    {
        $before = [$this->footprint('B'), $this->footprint('C')];
        $calls = 0;
        foreach ($this->components() as $class) {
            try {
                $page = Livewire::actingAs($this->learners['A']['user'])->test($class, $this->mountArguments($class, 'A'));
            } catch (\Throwable) {
                continue; // A component that needs more than these ids is still covered by the mount test above.
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || preg_match(self::LIFECYCLE, $method->getName())) {
                    continue;
                }
                $args = array_map(fn ($p) => $this->value($p, 'B'), $method->getParameters());
                try {
                    $page->call($method->getName(), ...$args);
                    $calls++;
                    $this->assertNothingOfOthers($page->html(), "{$class}::{$method->getName()} with B's ids");
                } catch (AssertionFailedError $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->assertNothingOfOthers($e->getMessage(), "{$class}::{$method->getName()} error");
                }
            }
        }
        $this->assertGreaterThan(100, $calls, 'actions called');
        $this->assertSame($before, [$this->footprint('B'), $this->footprint('C')], 'A changed another learner\'s records through a component action.');
    }
}
