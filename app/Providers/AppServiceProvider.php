<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\GitHubSignInProvider;
use App\Connectors\ConnectorCatalog;
use App\Mcp\StarCaller;
use App\Models\Connection;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\User;
use App\Outbound\DnsResolver;
use App\Outbound\GuardOutboundRequests;
use App\Outbound\OutboundGuard;
use App\Outbound\SystemDnsResolver;
use App\Stars\StarToolset;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
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
        $this->registerStarCaller();

        Passport::ignoreRoutes();
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
        $this->limitStarCalls();
        $this->configureStarOAuth();
        $this->limitOAuthRegistrations();
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
     * Whoever the access middleware authenticated the current request as,
     * on a Star's MCP endpoint.
     */
    protected function registerStarCaller(): void
    {
        $this->app->bind(StarCaller::class, fn (): StarCaller => StarCaller::of(request()));
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
     * anyone else's are simply not found. A Star's MCP endpoint has no
     * session and binds nothing: its access middleware finds the Star.
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

    /**
     * Calls to a Star are limited per credential, so one runaway client
     * can't use up the Star's downstream quotas or another client's share.
     */
    protected function limitStarCalls(): void
    {
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(config()->integer('nexus.limits.calls_per_minute'))
            ->by(StarCaller::of($request)->rateLimitKey));
    }

    /**
     * Nexus is the OAuth authorization server for Stars in OAuth mode, with
     * Passport. Its tokens carry the one scope MCP clients ask for,
     * `mcp:use` (also given to a client that asks for none), last an hour
     * and can be renewed for 30 days. Passport's own routes are left off:
     * routes/web.php and routes/ai.php declare only the ones Nexus uses,
     * with an approval that checks the Star's owner.
     */
    protected function configureStarOAuth(): void
    {
        Passport::tokensCan([Registrar::OAUTH_SCOPE => 'Use the tools switched on in one Star']);
        Passport::setDefaultScope([Registrar::OAUTH_SCOPE]);
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        Passport::authorizationView(fn (array $parameters): Response => $this->consentScreen($parameters));
    }

    /**
     * The consent screen, naming the client, the Star it registered with
     * and the signed-in user. It offers approval only when the user may
     * give it: for their own Star, while it uses OAuth.
     *
     * @param  array<string, mixed>  $parameters  Passport's: the client, the user, the scopes, the request and the auth token.
     */
    protected function consentScreen(array $parameters): Response
    {
        $client = $parameters['client'] ?? null;
        $user = $parameters['user'] ?? null;
        $app = $client instanceof Client && $user instanceof User ? StarOAuthClient::approvableBy($user, $client->id) : null;

        return response()->view('oauth.authorize', [
            'client' => $client,
            'user' => $user,
            'authToken' => $parameters['authToken'] ?? null,
            'app' => $app,
            'toolCount' => $app instanceof StarOAuthClient ? count(resolve(StarToolset::class)->enabledTools($app->star)) : 0,
        ]);
    }

    /**
     * Anyone may register an OAuth client with a Star in OAuth mode, so
     * registrations are limited per IP address.
     */
    protected function limitOAuthRegistrations(): void
    {
        RateLimiter::for('mcp-registration', fn (Request $request): Limit => Limit::perHour(config()->integer('nexus.limits.oauth_registrations_per_hour'))
            ->by($request->ip() ?? 'unknown'));
    }
}
