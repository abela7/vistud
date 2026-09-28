<?php

/*
| Only what ViStud changes; Livewire's own config fills in the rest.
*/

return [

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

    /*
    | Pages move without reloading (resources/js/page.js), which draws its
    | own loading line in the theme's accent: Livewire's bar gives screen
    | readers a role that doesn't exist.
    */
    'navigate' => [
        'show_progress_bar' => false,
    ],

];
