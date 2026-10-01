<?php

namespace App\Providers;

use App\Http\Client\PinOutboundRequests;
use App\Mcp\Vaults\VaultContext;
use App\Models\Vault;
use App\Security\DataKeys;
use App\Security\KeyWrapper;
use App\Security\KmsKeyWrapper;
use App\Security\LocalKeyWrapper;
use App\Security\OutboundGuard;
use Aws\Kms\KmsClient;
use BladeUI\Icons\Factory as IconFactory;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KeyWrapper::class, fn (Application $app): KeyWrapper => $this->makeKeyWrapper(
            (string) config('nexus.encryption.driver'),
        ));

        // Scoped, not a singleton: unwrapped data keys must never outlive the
        // request that needed them, even under Octane.
        $this->app->scoped(DataKeys::class, function (Application $app): DataKeys {
            $current = $app->make(KeyWrapper::class);
            $other = $current->name() === 'kms' ? 'local' : 'kms';

            return new DataKeys($current, rescue(
                fn (): array => [$other => $this->makeKeyWrapper($other)],
                [],
                report: false,
            ));
        });

        // Official service logos for connectors: resources/connectors/logos/slack.svg is "connector-slack".
        $this->callAfterResolving(IconFactory::class, fn (IconFactory $icons) => $icons->add('nexus-connectors', [
            'path' => resource_path('connectors/logos'),
            'prefix' => 'connector',
        ]));

        $this->app->singleton(OutboundGuard::class, fn (): OutboundGuard => new OutboundGuard(
            blockPrivateNetworks: (bool) config('nexus.outbound.block_private_networks'),
            requireHttps: (bool) config('nexus.outbound.require_https'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardOutboundRequests();

        RateLimiter::for('mcp', function (Request $request): Limit {
            $key = app()->bound(VaultContext::class)
                ? app(VaultContext::class)->rateLimitKey
                : 'ip:'.$request->ip();

            return Limit::perMinute(config('nexus.limits.calls_per_minute'))->by($key);
        });

        // Dynamic client registration is open by design; keep it from being used to flood the clients table.
        RateLimiter::for('oauth-registration', fn (Request $request): Limit => Limit::perHour(20)->by($request->ip()));

        $this->configureOAuthServer();
    }

    /**
     * Every request made through the Http client (downstream MCP traffic and
     * OAuth discovery) must go to a public address, pinned for the request.
     * Redirects are refused so they can't bounce into a private network.
     */
    protected function guardOutboundRequests(): void
    {
        Http::globalOptions(['allow_redirects' => false]);

        Http::globalMiddleware(fn (callable $handler): Closure => app(PinOutboundRequests::class)($handler));
    }

    /**
     * Nexus as an OAuth authorization server, for OAuth-mode vaults.
     */
    protected function configureOAuthServer(): void
    {
        Registrar::ensureMcpScope();

        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::authorizationView(fn (array $parameters) => view('oauth.authorize', [
            ...$parameters,
            'vault' => Vault::query()
                ->whereHas('oauthClients', fn ($clients) => $clients->whereKey($parameters['client']->getKey()))
                ->first(),
        ]));
    }

    protected function makeKeyWrapper(string $driver): KeyWrapper
    {
        return match ($driver) {
            'local' => new LocalKeyWrapper((string) config('nexus.encryption.local.master_key')),
            'kms' => new KmsKeyWrapper(
                new KmsClient(['version' => '2014-11-01', 'region' => config('nexus.encryption.kms.region')]),
                (string) config('nexus.encryption.kms.key_id') ?: throw new InvalidArgumentException('NEXUS_KMS_KEY_ID is required for the kms driver.'),
            ),
            default => throw new InvalidArgumentException("Unknown key driver [{$driver}]."),
        };
    }
}
