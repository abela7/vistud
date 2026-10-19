<?php

namespace App\Console\Commands;

use App\Mcp\Plugins\PluginBuilder;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/** Writes the install packages for Claude, ChatGPT and Gemini from resources/skills (plan M6.4), folders and zips. */
class BuildMcpPlugins extends Command
{
    protected $signature = 'vistud:mcp:plugins {--out=plugins : The folder to write to}';

    protected $description = 'Build the ViStud install packages for chat apps from one source';

    public function handle(PluginBuilder $builder, Filesystem $files): int
    {
        $out = base_path((string) $this->option('out'));
        foreach (PluginBuilder::TARGETS as $target) {
            $folder = $out.'/'.$target.'/'.PluginBuilder::NAME;
            $files->deleteDirectory($folder);
            $built = $builder->files($target);
            foreach ($built as $name => $content) {
                $files->ensureDirectoryExists(dirname($folder.'/'.$name));
                $files->put($folder.'/'.$name, $content);
            }
            $files->move($builder->zip($target), $out.'/'.$target.'/'.PluginBuilder::NAME.'-'.$target.'.zip');
            $this->line("{$target}: ".count($built).' files, '.PluginBuilder::url());
        }

        return self::SUCCESS;
    }
}
