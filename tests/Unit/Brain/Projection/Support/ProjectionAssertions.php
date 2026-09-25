<?php

namespace Tests\Unit\Brain\Projection\Support;

/** Readable assertions on projector output for WP4 developer tests. */
trait ProjectionAssertions
{
    protected function assertTopic(array $projection, string $topic, string $label, ?array $flags = null): void
    {
        $this->assertArrayHasKey($topic, $projection['topics'], "Topic {$topic} is missing.");
        $actual = $projection['topics'][$topic];
        $this->assertSame($label, $actual['label'], "Label of {$topic} (flags: ".implode(',', $actual['flags']).')');
        if ($flags !== null) {
            sort($flags);
            $this->assertSame($flags, $actual['flags'], "Flags of {$topic}");
        }
    }

    protected function assertHasFlag(array $projection, string $topic, string $flag, bool $expected = true): void
    {
        $this->assertSame($expected, in_array($flag, $projection['topics'][$topic]['flags'], true), ($expected ? 'Expected' : 'Did not expect')." {$flag} on {$topic}");
    }

    protected function assertMisconception(array $projection, string $id, ?string $state, ?int $resurfaced = null): void
    {
        if ($state === null) {
            $this->assertArrayNotHasKey($id, $projection['misconceptions']);

            return;
        }
        $this->assertSame($state, $projection['misconceptions'][$id]['state'] ?? null, "State of {$id}");
        if ($resurfaced !== null) {
            $this->assertSame($resurfaced, $projection['misconceptions'][$id]['resurfaced_count']);
        }
    }

    protected function assertQuestion(array $projection, string $id, string $state, ?array $flags = null, ?int $resurfaced = null): void
    {
        $this->assertSame($state, $projection['questions'][$id]['state'] ?? null, "State of {$id}");
        if ($flags !== null) {
            sort($flags);
            $this->assertSame($flags, $projection['questions'][$id]['flags'], "Flags of {$id}");
        }
        if ($resurfaced !== null) {
            $this->assertSame($resurfaced, $projection['questions'][$id]['resurfaced_count'], "Resurfaced count of {$id}");
        }
    }
}
