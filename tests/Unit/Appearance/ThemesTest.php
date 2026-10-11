<?php

namespace Tests\Unit\Appearance;

use App\Appearance\Themes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ThemesTest extends TestCase
{
    public function test_a_theme_to_choose_pairs_a_light_theme_with_a_dark_one(): void
    {
        $pairs = $this->themes()->pairs(['blossom' => ['name' => 'Blossom', 'light' => 'blossom', 'dark' => 'blossom-dark']]);

        $this->assertSame('Blossom', $pairs['blossom']['name']);
        $this->assertSame(['blossom', 'blossom-dark'], [$pairs['blossom']['light']->id, $pairs['blossom']['dark']->id]);
    }

    public function test_a_pair_the_wrong_way_round_or_of_an_unknown_theme_is_refused(): void
    {
        foreach ([['light' => 'blossom-dark', 'dark' => 'blossom'], ['light' => 'blossom', 'dark' => 'no-such-theme']] as $pair) {
            try {
                $this->themes()->pairs(['x' => ['name' => 'X', ...$pair]]);
                $this->fail('The pair '.implode(' / ', $pair).' was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function themes(): Themes
    {
        return new Themes(dirname(__DIR__, 3).'/resources/themes');
    }
}
