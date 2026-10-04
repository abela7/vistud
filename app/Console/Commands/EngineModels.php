<?php

namespace App\Console\Commands;

use App\Engine\EngineFailed;
use App\Engine\Model;
use App\Engine\Models;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** The models the engine's service offers, with their prices, to pick from and to check the key works. */
#[Signature('vistud:engine:models {--find= : Only models whose id or name contains this} {--fresh : Ask the service again instead of the list kept for a day}')]
#[Description('List the models the engine offers, with their prices and what they take')]
class EngineModels extends Command
{
    public function handle(Models $models): int
    {
        try {
            $list = $models->all((bool) $this->option('fresh'));
        } catch (EngineFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $find = mb_strtolower((string) $this->option('find'));
        $list = array_values(array_filter($list, fn (Model $m) => $find === '' || str_contains(mb_strtolower($m->id.' '.$m->name), $find)));
        if ($list === []) {
            $this->warn($models->all() === [] ? 'No models: is VISTUD_ENGINE_KEY set and the service reachable? Try `php artisan vistud:doctor`.' : 'No model matches.');

            return self::FAILURE;
        }
        $this->table(['Id (use this in the settings)', 'Name', 'Price', 'Context', 'Tools', 'Pictures', 'Files'], array_map(fn (Model $m) => [
            $m->id, $m->name, $m->priceWords(), number_format($m->contextLength), $m->tools ? 'yes' : '', $m->images ? 'yes' : '', $m->files ? 'yes' : '',
        ], $list));
        $this->line(count($list).' models.');

        return self::SUCCESS;
    }
}
