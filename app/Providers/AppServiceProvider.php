<?php

namespace App\Providers;

use App\Appearance\Themes;
use App\Engine\Engine;
use App\Engine\Jobs\Heartbeat;
use App\Engine\OpenRouter;
use App\Engine\Setup;
use App\Engine\SharedKeyPin;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnterAdminArea;
use App\Mcp\Http\AuthorizeConnection;
use App\Platform\Access\LearnerKeys;
use App\Platform\Access\Principal;
use App\Platform\Database\RuntimeGrants;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Passport;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerKeys::class);
        $this->app->singleton(Themes::class, fn () => new Themes(resource_path('themes')));
        // The built-in chat's engine: OpenRouter, or any service with the OpenAI chat format (config/vistud.php).
        // The shared key for students runs only the model the admin chose (App\Engine\SharedKeyPin).
        $this->app->bind(Engine::class, fn ($app) => new SharedKeyPin($app->make(OpenRouter::class), $app->make(Setup::class)));
        // An AI app's sign-in always shows ViStud's consent page, which may not be framed (docs/specs/mcp-connections-plan.md §3.2).
        $this->app->bind(AuthorizationController::class, AuthorizeConnection::class);
        // Passport gives its own controller the web login by class name; the subclass needs the same, or the code
        // could be issued to whichever user a cached guard last held.
        $this->app->when(AuthorizeConnection::class)->needs(StatefulGuard::class)->give(fn () => Auth::guard(config('passport.guard')));
        // No device-code sign-in: its table is not made and no AI app needs it.
        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // A Livewire action re-runs its page's access checks, so an admin
        // component can't be reached by posting to Livewire's own endpoint
        // (ADR 0003 §10.3, T2). The services check again.
        Livewire::addPersistentMiddleware([EnsureRole::class, EnsureTwoFactorEnrolled::class, EnterAdminArea::class]);

        // Pages move without reloading (resources/js/page.js); after a new build
        // the next move reloads once, so the new scripts and styles are used.
        Vite::useScriptTagAttributes(['data-navigate-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-navigate-track' => 'reload']);

        // A queue worker says it is there while it loops and whenever it takes a job: the reader's and the helper's
        // jobs go on the queue only while one is (App\Engine\Jobs\Runner), otherwise they run after the response.
        Event::listen(Looping::class, fn () => Heartbeat::beat());
        Event::listen(JobProcessing::class, fn () => Heartbeat::beat());
        // A sync/after-response job shares its caller's container. Unlike queue:work,
        // it does not flush scoped instances itself. Never retain keys across either boundary.
        Event::listen(JobProcessing::class, function () {
            if ($this->app->resolved(LearnerKeys::class)) {
                $this->app->make(LearnerKeys::class)->forget();
            }
        });
        Event::listen(JobAttempted::class, function () {
            if ($this->app->resolved(LearnerKeys::class)) {
                $this->app->make(LearnerKeys::class)->forget();
            }
        });

        // AI apps over MCP (docs/specs/mcp-connections-plan.md §3.2): an hour per access token, 30 days per refresh token,
        // ViStud's consent page; calls limited per student (a reconnect does not reset it) and, before sign-in, per IP.
        Passport::authorizationView('mcp.authorize');
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        RateLimiter::for('mcp', function (Request $request) {
            $principal = $request->attributes->get('vistud.principal');
            $key = $principal instanceof Principal && $principal->learnerId !== null ? $principal->learnerId : $request->ip();

            return Limit::perMinute((int) config('mcp.per_minute', 60))->by('mcp:'.$key);
        });
        RateLimiter::for('mcp-ip', fn (Request $request) => Limit::perMinute(300)->by('mcp-ip:'.$request->ip()));
        RateLimiter::for('mcp-register', fn (Request $request) => Limit::perMinute((int) config('mcp.register_per_minute', 10))->by('mcp-register:'.$request->ip()));

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
