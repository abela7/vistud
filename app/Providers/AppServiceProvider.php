<?php

namespace App\Providers;

use App\Appearance\Themes;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnterAdminWorkspace;
use App\Platform\Database\RuntimeGrants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
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

        // A Livewire action re-runs its page's access checks, so an admin
        // component can't be reached by posting to Livewire's own endpoint
        // (ADR 0003 §10.3, T2). The services check again.
        Livewire::addPersistentMiddleware([EnsureRole::class, EnsureTwoFactorEnrolled::class, EnterAdminWorkspace::class]);

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
