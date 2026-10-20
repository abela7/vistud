<?php

namespace App\Console\Commands;

use App\Platform\Files\TempWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PruneFileTemps extends Command
{
    protected $signature = 'vistud:files:prune-temps';

    protected $description = 'Remove abandoned private processing workspaces, preserving active leases.';

    public function handle(): int
    {
        $this->line('Removed '.TempWorkspace::prune().' abandoned processing directories.');
        $disk = Storage::disk('temporary');
        $expired = array_filter($disk->allFiles('livewire-tmp'), fn ($path) => $disk->lastModified($path) < time() - 3600);
        $disk->delete($expired);
        $this->line('Removed '.count($expired).' expired temporary uploads.');

        return self::SUCCESS;
    }
}
