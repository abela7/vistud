<?php

declare(strict_types=1);

namespace Tests\Feature\Study;

use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassSessions;
use App\Study\Modules;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A class belongs to one student and one module. */
class ClassSessionsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_a_class_is_kept_for_its_module_and_hidden_from_everyone_else(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $other = app(Modules::class)->create($by, $course->id, ['title' => 'Genetics']);
        $classes = app(ClassSessions::class);

        $class = $classes->create($by, $course->id, $cells->id, [
            'title' => 'Week 3 lecture',
            'kind' => 'lecture',
            'held_on' => '2026-10-14',
            'teacher' => 'Dr Smith',
        ]);

        $this->assertSame('Week 3 lecture', $class->title);
        $this->assertSame('lecture', $class->kind);
        $this->assertSame('2026-10-14', $class->heldOn);
        $this->assertSame('Dr Smith', $class->teacher);
        $this->assertSame(ClassSessions::NEW, $class->status);
        $this->assertSame('Lecture · 14 Oct 2026 · Dr Smith', $class->line());
        $this->assertSame([$class->id], array_map(fn ($row) => $row->id, $classes->forModule($by, $course->id, $cells->id)));
        $this->assertSame([], $classes->forModule($by, $course->id, $other->id));

        $grace = $this->principal($this->student());
        $this->expectException(NotFound::class);
        $classes->find($grace, $class->id);
    }

    public function test_a_class_needs_a_name_a_known_kind_and_a_real_day(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($by, $course->id, ['title' => 'Cells']);
        $classes = app(ClassSessions::class);

        try {
            $classes->create($by, $course->id, $cells->id, ['title' => '  ', 'kind' => 'recital', 'held_on' => 'Tuesday']);
            $this->fail('A blank class was accepted.');
        } catch (Unprocessable $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertArrayHasKey('title', $e->details['fields']);
            $this->assertArrayHasKey('kind', $e->details['fields']);
            $this->assertArrayHasKey('held_on', $e->details['fields']);
        }

        $this->assertSame([], $classes->forModule($by, $course->id, $cells->id));
    }
}
