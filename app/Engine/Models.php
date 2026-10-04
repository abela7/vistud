<?php

namespace App\Engine;

use Illuminate\Support\Facades\Cache;

/**
 * The models the service offers, kept for a day (the list is long and changes rarely), so the settings can
 * show them with their prices and the chat can tell what a model takes. The list is the same for every key, so
 * one copy serves everyone, asked with whatever key is at hand. When the service can't be asked, the list is
 * empty and nothing is kept.
 */
final class Models
{
    private const KEY = 'vistud.engine.models';

    public function __construct(private Engine $engine) {}

    /** @return list<Model> */
    public function all(bool $fresh = false, ?string $key = null): array
    {
        if ($fresh) {
            Cache::forget(self::KEY);
        }
        $cached = Cache::get(self::KEY);
        if (is_array($cached)) {
            return array_map(fn (array $m) => new Model(...$m), $cached);
        }
        try {
            $models = $this->engine->models($key);
        } catch (EngineFailed) {
            return [];
        }
        Cache::put(self::KEY, array_map(fn (Model $m) => get_object_vars($m), $models), now()->addHours((int) config('vistud.engine.models_cache_hours')));

        return $models;
    }

    public function find(string $id, ?string $key = null): ?Model
    {
        foreach ($this->all(key: $key) as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        return null;
    }
}
