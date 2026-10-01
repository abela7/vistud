<?php

namespace App\Providers;

use App\Appearance\Themes;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnterAdminArea;
use App\Platform\Database\RuntimeGrants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Themes::class, fn () => new Themes(resource_path('themes')));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->runningInConsole()) {
            \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables = array_merge(
                \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables,
                ['TEMP', 'TMP', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA']
            );
        }

        // A Livewire action re-runs its page's access checks, so an admin
        // component can't be reached by posting to Livewire's own endpoint
        // (ADR 0003 §10.3, T2). The services check again.
        Livewire::addPersistentMiddleware([EnsureRole::class, EnsureTwoFactorEnrolled::class, EnterAdminArea::class]);

        // Pages move without reloading (resources/js/page.js); after a new build
        // the next move reloads once, so the new scripts and styles are used.
        Vite::useScriptTagAttributes(['data-navigate-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-navigate-track' => 'reload']);

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
