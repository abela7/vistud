<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vistud:trash:purge')->daily();
Schedule::command('vistud:sessions:settle')->everyTenMinutes();
Schedule::command('vistud:journal:rebuild')->daily();
Schedule::command('vistud:engine:prune-threads')->daily();
Schedule::command('vistud:db:orphans')->daily();
Schedule::command('passport:purge')->hourly();
Schedule::command('vistud:mcp:prune')->daily();
