<?php

namespace Tests\Unit\Brain\Projection;

use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\Projector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\GoldenScenario;
use Tests\Unit\Brain\Projection\Support\Scenario;

/**
 * ADR 0001 principle 3 and ADR 0002 §1: derived state is a pure function of
 * the journal. Same entries and options, same result; content text plays no
 * part; what the brain believed at a position never changes afterwards.
 */
class ReconstructionTest extends TestCase
{
    private const NOW = '2027-02-03T21:00:00+00:00';

    public function test_the_same_journal_always_projects_to_byte_identical_json(): void
    {
        $entries = GoldenScenario::build()->entries();
        $options = new ProjectionOptions(new DateTimeImmutable(self::NOW));

        $first = json_encode((new Projector)->project($entries, $options), JSON_THROW_ON_ERROR);
        $second = json_encode((new Projector)->project($entries, $options), JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_the_order_entries_are_supplied_in_does_not_matter(): void
    {
        $entries = GoldenScenario::build()->entries();
        $options = new ProjectionOptions(new DateTimeImmutable(self::NOW));
        $expected = json_encode((new Projector)->project($entries, $options));

        mt_srand(20261013);
        for ($i = 0; $i < 5; $i++) {
            $shuffled = $entries;
            shuffle($shuffled);
            $this->assertSame($expected, json_encode((new Projector)->project($shuffled, $options)));
        }
    }

    public function test_content_text_plays_no_part(): void
    {
        $with = GoldenScenario::build();
        $without = Scenario::make();
        foreach ($with->specs() as $spec) {
            if (isset($spec['content'])) {
                // Keep the field names (ADR 0002 contracts need some), empty the text.
                $spec['content'] = array_map(fn () => '', $spec['content']);
            }
            $without->add($spec);
        }

        $this->assertSame($with->project('2027-02-03 21:00'), $without->project('2027-02-03 21:00'));
    }

    public function test_what_the_brain_believed_at_a_position_never_changes_when_later_entries_arrive(): void
    {
        // A6: replaying up to a position gives the same answer however much came after.
        $all = GoldenScenario::build();
        foreach ([47, 49, 64, 79] as $position) {
            $prefix = Scenario::make();
            foreach (array_slice($all->specs(), 0, $position) as $spec) {
                $prefix->add($spec);
            }
            $this->assertSame(
                $prefix->project('2027-02-03 21:00'),
                $all->project('2027-02-03 21:00', maxPosition: $position),
                "Belief at position {$position}",
            );
        }
    }
}
