<?php

/*
| Only what ViStud changes; Livewire's own config fills in the rest.
*/

return [

    /*
    | The navigation progress bar takes the theme's accent, never a colour
    | of its own (DESIGN.md §3).
    */
    // Uploads wait here until App\Study\Files checks and stores them. The
    // size limit matches vistud.files.max_bytes (in kilobytes).
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:25600'],
        'directory' => 'livewire-tmp',
        'middleware' => null,
        // No previews of files that haven't been checked yet.
        'preview_mimes' => [],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => 'var(--accent)',
    ],

];
