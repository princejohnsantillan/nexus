<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakeMcpServer;

/*
 * Signing a Connection in with OAuth, when the server or its authorization
 * server fails (discovery, registration or the callback), keeps their text
 * (DownstreamCanary::TEXT) out of the log, and tells the user what went
 * wrong in Nexus's own words. Renewals are covered with tool calls.
 */

const SIGN_IN_METADATA_URL = 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp';

/**
 * A server that refuses Nexus without credentials, with this challenge,
 * and whose protected-resource metadata answers like this.
 */
function withResourceMetadata(PromiseInterface $metadata, string $challenge = 'Bearer resource_metadata="'.SIGN_IN_METADATA_URL.'"'): void
{
    FakeMcpServer::at()->requireHeader('Authorization', 'Bearer never-issued')->challengingWith($challenge);

    Http::fake([SIGN_IN_METADATA_URL => $metadata]);
}

/**
 * The metadata of the authorization server at https://login.example.com,
 * with these fields replaced.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function issuerMetadata(array $fields = []): array
{
    return [
        'issuer' => 'https://login.example.com',
        'authorization_endpoint' => 'https://login.example.com/authorize',
        'token_endpoint' => 'https://login.example.com/token',
        'registration_endpoint' => 'https://login.example.com/register',
        'code_challenge_methods_supported' => ['S256'],
        ...$fields,
    ];
}

/**
 * A server whose protected-resource metadata names the authorization
 * server at https://login.example.com, whose metadata answers like this,
 * and whose registration endpoint registers Nexus unless told otherwise.
 *
 * @param  (Closure(Request): PromiseInterface)|PromiseInterface|null  $registration
 */
function withIssuerMetadata(PromiseInterface $metadata, Closure|PromiseInterface|null $registration = null): void
{
    withResourceMetadata(Http::response(['resource' => FakeMcpServer::DEFAULT_URL, 'authorization_servers' => ['https://login.example.com']]));

    Http::fake([
        'https://login.example.com/.well-known/*' => $metadata,
        'https://login.example.com/register' => $registration ?? Http::response(['client_id' => 'client-1'], 201),
    ]);
}

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->freezeTime();
    $this->canary = DownstreamCanary::watch();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->connection = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
});

describe('starting', function (): void {
    it('says why signing in can\'t start, in Nexus\'s words', function (Closure $server): void {
        $server();

        $response = $this->get(route('connections.connect', $this->connection))->assertRedirect(route('connections.show', $this->connection));

        expect(session('toast.variant'))->toBe('danger')
            ->and(session('toast.text'))->toStartWith('Nexus couldn\'t start signing in.')->not->toContain(DownstreamCanary::TEXT)
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    })->with([
        'the server fails without credentials' => [function (): void {
            FakeMcpServer::at()->respondTo('initialize', FakeMcpServer::httpStatus(500, DownstreamCanary::TEXT));
        }],
        'the challenge names metadata that fails' => [function (): void {
            withResourceMetadata(Http::response(DownstreamCanary::TEXT, 500), 'Bearer error_description="'.DownstreamCanary::TEXT.'", resource_metadata="'.SIGN_IN_METADATA_URL.'"');
        }],
        'the challenge names a malformed metadata URL' => [function (): void {
            withResourceMetadata(Http::response(DownstreamCanary::TEXT, 500), 'Bearer resource_metadata="https://mcp.example.com:'.DownstreamCanary::TEXT.'/metadata"');
        }],
        'the resource metadata is malformed' => [function (): void {
            withResourceMetadata(Http::response('{"resource":"'.DownstreamCanary::TEXT, 200));
        }],
        'the resource metadata names another resource' => [function (): void {
            withResourceMetadata(Http::response(['resource' => 'https://'.DownstreamCanary::TEXT.'.example.com/mcp']));
        }],
        'the resource metadata names an authorization server off HTTPS' => [function (): void {
            withResourceMetadata(Http::response(['resource' => FakeMcpServer::DEFAULT_URL, 'authorization_servers' => [DownstreamCanary::TEXT]]));
        }],
        'the authorization server\'s metadata fails' => [function (): void {
            withIssuerMetadata(Http::response(DownstreamCanary::TEXT, 500));
        }],
        'the authorization server\'s metadata is malformed' => [function (): void {
            withIssuerMetadata(Http::response('{"issuer":"'.DownstreamCanary::TEXT, 200));
        }],
        'the authorization server\'s metadata names another issuer' => [function (): void {
            withIssuerMetadata(Http::response(['issuer' => 'https://'.DownstreamCanary::TEXT.'.example.com']));
        }],
        'the authorization server names a malformed sign-in page' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['authorization_endpoint' => 'https://login.example.com:'.DownstreamCanary::TEXT.'/authorize'])));
        }],
        'the authorization server names a sign-in page with an unclosed IPv6 host' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['authorization_endpoint' => 'https://[2001:db8::1/'.DownstreamCanary::TEXT])));
        }],
        'the authorization server names a sign-in page with a control character' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['authorization_endpoint' => 'https://login.example.com/'.DownstreamCanary::TEXT."\u{1}"])));
        }],
        'the authorization server names a sign-in page with spaces' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['authorization_endpoint' => 'https://login.example.com/'.DownstreamCanary::TEXT.' and spaces'])));
        }],
        'the authorization server lacks PKCE' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['code_challenge_methods_supported' => [DownstreamCanary::TEXT]])));
        }],
        'the authorization server names a malformed registration endpoint' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata(['registration_endpoint' => 'https://login.example.com:'.DownstreamCanary::TEXT.'/register'])));
        }],
        'the registration endpoint refuses Nexus' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata()), Http::response(['error' => 'invalid_client_metadata', 'error_description' => DownstreamCanary::TEXT], 400));
        }],
        'the registration endpoint fails' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata()), Http::response(DownstreamCanary::TEXT, 500));
        }],
        'the registration endpoint answers malformed JSON' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata()), Http::response('{"client_id":"'.DownstreamCanary::TEXT, 201));
        }],
        'the registration endpoint names no client' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata()), Http::response(['client_name' => DownstreamCanary::TEXT], 201));
        }],
        'the registration endpoint breaks off' => [function (): void {
            withIssuerMetadata(Http::response(issuerMetadata()), DownstreamCanary::brokenTransfer(...));
        }],
    ]);
});

describe('the callback', function (): void {
    beforeEach(function (): void {
        $this->server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
        $this->auth = $this->server->authorizationServer();
    });

    it('reports a refusal from the sign-in page in Nexus\'s words', function (array $query): void {
        $state = Uri::of(ConnectionOAuthFlow::start($this, $this->connection))->query()->get('state');

        $response = $this->get(route('oauth.callback', ['state' => $state, ...$query]))->assertRedirect(route('connections.show', $this->connection));

        expect(session('toast.variant'))->toBe('danger')
            ->and(session('toast.text'))->not->toContain(DownstreamCanary::TEXT)
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    })->with([
        'its own error code' => [['error' => DownstreamCanary::TEXT, 'error_description' => DownstreamCanary::TEXT, 'error_uri' => 'https://auth.example.com/'.DownstreamCanary::TEXT]],
        'a standard error code' => [['error' => 'access_denied', 'error_description' => DownstreamCanary::TEXT]],
        'another issuer' => [['code' => 'code-1', 'iss' => 'https://'.DownstreamCanary::TEXT.'.example.com']],
    ]);

    it('reports a code the token endpoint won\'t exchange in Nexus\'s words', function (Closure $answer): void {
        $this->auth->respondTo('token', $answer);
        $location = ConnectionOAuthFlow::start($this, $this->connection);

        $response = $this->get($this->auth->approve($location))->assertRedirect(route('connections.show', $this->connection));

        expect(session('toast.variant'))->toBe('danger')
            ->and(session('toast.text'))->not->toContain(DownstreamCanary::TEXT)
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->connection->refresh()->last_error ?? '')->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::tokenEndpointFailures());

    it('finishes a sign-in whose token states an odd lifetime, as a token without one', function (mixed $expiresIn): void {
        $this->auth->issuingTokensFor(null)->withTokenFields(['expires_in' => $expiresIn, 'workspace_name' => DownstreamCanary::TEXT]);
        $location = ConnectionOAuthFlow::start($this, $this->connection);

        $this->get($this->auth->approve($location))
            ->assertRedirect(route('connections.show', $this->connection))
            ->assertSessionHas('toast', ['variant' => 'success', 'text' => 'Signed in. Nexus loaded 1 tool.']);

        expect($this->connection->refresh()->secrets->get('expires_at'))->toBeNull()
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::oddTokenLifetimes());

    it('refuses a malformed token endpoint in Nexus\'s words', function (): void {
        $this->auth->withMetadata(['token_endpoint' => 'https://auth.example.com:'.DownstreamCanary::TEXT.'/token']);
        $location = ConnectionOAuthFlow::start($this, $this->connection);

        $response = $this->get($this->auth->approve($location))->assertRedirect(route('connections.show', $this->connection));

        expect(session('toast.variant'))->toBe('danger')
            ->and(session('toast.text'))->not->toContain(DownstreamCanary::TEXT)
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    });
});
