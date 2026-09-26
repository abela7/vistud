<?php

namespace App\Console\Commands;

use App\Appearance\Themes;
use App\Appearance\ThemeValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Checks every built-in theme's contrast, then writes the theme stylesheet
 * and the sentinel-theme fixture from resources/themes/*.json
 * (ADR 0003 §6, DESIGN.md §3). A theme that fails is never written.
 */
#[Signature('vistud:themes:build {--check : Fail if the generated files are out of date, without writing}')]
#[Description('Validate the built-in themes and generate their stylesheet.')]
class BuildThemes extends Command
{
    public function handle(Themes $themes, ThemeValidator $validator): int
    {
        $failed = false;
        foreach ($themes->all() as $theme) {
            foreach ($validator->failures($theme) as $failure) {
                $failed = true;
                $this->error("{$theme->id}: {$failure['pair']} is {$failure['actual']}:1 at {$failure['at']}; needs {$failure['minimum']}:1. {$failure['suggestion']}");
            }
        }
        if ($failed) {
            return self::FAILURE;
        }

        $files = [
            Themes::STYLESHEET => $themes->stylesheet(config('vistud.appearance.light')),
            Themes::SENTINEL_FIXTURE => json_encode($themes->sentinel(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ];

        $stale = false;
        foreach ($files as $path => $contents) {
            $absolute = base_path($path);
            $current = is_file($absolute) ? file_get_contents($absolute) : null;
            if ($current === $contents) {
                continue;
            }
            if ($this->option('check')) {
                $this->error("{$path} is out of date. Run php artisan vistud:themes:build.");
                $stale = true;

                continue;
            }
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }
            file_put_contents($absolute, $contents);
            $this->info("Wrote {$path}.");
        }

        if (! $stale) {
            $this->info(count($themes->all()).' themes pass the contrast checks.');
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
