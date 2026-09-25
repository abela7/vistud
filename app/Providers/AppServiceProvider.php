<?php

namespace App\Providers;

use App\Platform\Database\RuntimeGrants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // New tables get their runtime-user privileges as soon as the schema
        // owner has created them (docs/development/setup.md).
        Event::listen(MigrationsEnded::class, function () {
            $owner = config('vistud.database.owner_connection');
            if ($this->app['migrator']->getConnection() === $owner) {
                $this->app->make(RuntimeGrants::class)->apply($owner);
            }
        });
    }
}
