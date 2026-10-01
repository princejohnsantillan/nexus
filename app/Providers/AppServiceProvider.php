<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\GitHubSignInProvider;
use App\Connectors\ConnectorCatalog;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Outbound\DnsResolver;
use App\Outbound\GuardOutboundRequests;
use App\Outbound\OutboundGuard;
use App\Outbound\SystemDnsResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerOutboundGuard();
        $this->registerConnectorCatalog();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->guardOutboundRequests();
        $this->configureGitHubSignIn();
        $this->bindOwnRecords();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * The guard's switches can only be relaxed in the local environment, for
     * testing against a local MCP server. Everywhere else both are on.
     */
    protected function registerOutboundGuard(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        $this->app->bind(OutboundGuard::class, fn (): OutboundGuard => new OutboundGuard(
            $this->app->make(DnsResolver::class),
            blockPrivateNetworks: ! $this->app->isLocal() || config()->boolean('nexus.outbound.block_private_networks'),
            requireHttps: ! $this->app->isLocal() || config()->boolean('nexus.outbound.require_https'),
        ));
    }

    /**
     * The gallery's connectors, read once per request from
     * `resources/connectors`.
     */
    protected function registerConnectorCatalog(): void
    {
        $this->app->singleton(ConnectorCatalog::class, fn (): ConnectorCatalog => new ConnectorCatalog(resource_path('connectors')));
    }

    /**
     * Send every HTTP client request through the outbound guard, and never
     * follow redirects. Global options replace each other, so any option
     * added here later must keep redirects off.
     */
    protected function guardOutboundRequests(): void
    {
        Http::globalOptions(['allow_redirects' => false]);

        Http::globalMiddleware(fn (callable $handler): Closure => $this->app->make(GuardOutboundRequests::class)($handler));
    }

    /**
     * Sign in with GitHub using the scopes Nexus needs.
     */
    protected function configureGitHubSignIn(): void
    {
        Socialite::extend('github', fn (): AbstractProvider => Socialite::buildProvider(
            GitHubSignInProvider::class,
            config()->array('services.github'),
        ));
    }

    /**
     * Route parameters resolve only to the signed-in user's own records, so
     * anyone else's are simply not found.
     */
    protected function bindOwnRecords(): void
    {
        Route::pattern('connection', '[0-9]+');

        Route::bind('connection', function (string $id): Connection {
            $user = Auth::user();

            abort_unless($user instanceof User, 404);

            return $user->connections()->findOrFail($id);
        });

        Route::pattern('star', '[a-z0-9]{'.Star::PUBLIC_ID_LENGTH.'}');

        Route::bind('star', function (string $publicId): Star {
            $user = Auth::user();

            abort_unless($user instanceof User, 404);

            return $user->stars()->where('public_id', $publicId)->firstOrFail();
        });
    }
}
