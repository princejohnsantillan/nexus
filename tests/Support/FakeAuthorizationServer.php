<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use InvalidArgumentException;

/**
 * An OAuth authorization server for tests, answering through Http::fake().
 * Put it in front of a FakeMcpServer with requireOAuth():
 *
 *     $server = FakeMcpServer::at()->withTools([...])->requireOAuth();
 *     $auth = $server->authorizationServer();          // at https://auth.example.com
 *
 *     $callback = $auth->approve($authorizationUrl);  // the user approves Nexus on its sign-in page
 *
 * Out of the box it publishes RFC 8414 metadata with S256 PKCE, registers
 * clients dynamically (each with a secret), issues access tokens that last
 * an hour, and rotates refresh tokens: a used one is refused. It checks
 * everything a real one does: the client and its secret, the redirect URI,
 * the PKCE verifier and the resource. Script it further with:
 *
 * - withoutRegistration(), registeringPublicClients(), acceptingClient():
 *   which clients it knows; acceptingMetadataDocuments() takes a Client ID
 *   Metadata Document URL as a client ID.
 * - publishingMetadataAt(): `oauth` (default), `openid` (OpenID
 *   configuration, path inserted) or `openid-suffix` (path appended);
 *   namedInMetadataAs() names another issuer in it; withMetadata() adds or
 *   overrides fields, e.g. `code_challenge_methods_supported`.
 * - namingItselfOnReturn(): it adds `iss` when sending the user back and
 *   says so in its metadata.
 * - issuingTokensFor(): how long access tokens last (null: forever);
 *   keepingRefreshTokens() stops rotating them; withoutRefreshTokens();
 *   withTokenFields() adds fields to every token response, such as the
 *   `workspace_name` Notion names the account with.
 * - respondTo('token' | 'register', $responder): replace an endpoint's answer.
 * - beforeAnswering('token' | 'register', $callback): run something while that
 *   request is in flight, to play out a race.
 *
 * Afterwards, tokenRequests() and registrations() return what it received,
 * and accepts() says whether an access token is one it issued and still good.
 */
final class FakeAuthorizationServer
{
    public const string DEFAULT_ISSUER = 'https://auth.example.com';

    private bool $registers = true;

    private bool $registersPublicClients = false;

    private bool $acceptsMetadataDocuments = false;

    private bool $namesItselfOnReturn = false;

    private string $metadataKind = 'oauth';

    private ?string $namedIssuer = null;

    /**
     * @var array<string, mixed>
     */
    private array $extraMetadata = [];

    private ?int $tokenLifetime = 3600;

    private bool $rotatesRefreshTokens = true;

    private bool $issuesRefreshTokens = true;

    /**
     * @var array<string, mixed>
     */
    private array $tokenFields = [];

    /**
     * Known clients: their secrets, null for public clients.
     *
     * @var array<string, string|null>
     */
    private array $clients = [];

    /**
     * @var array<string, Closure(Request): PromiseInterface>
     */
    private array $responders = [];

    /**
     * @var array<string, Closure(Request): mixed>
     */
    private array $beforeAnswering = [];

    /**
     * Codes not yet exchanged.
     *
     * @var array<string, array{client_id: string, redirect_uri: string, challenge: string, resource: string|null}>
     */
    private array $codes = [];

    /**
     * Good access tokens and when each expires (null: never).
     *
     * @var array<string, int|null>
     */
    private array $accessTokens = [];

    /**
     * Good refresh tokens and the client each was issued to.
     *
     * @var array<string, string>
     */
    private array $refreshTokens = [];

    private int $issued = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $tokenRequests = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $registrations = [];

    private function __construct(public readonly string $issuer) {}

    public static function at(string $issuer = self::DEFAULT_ISSUER): self
    {
        $server = new self(rtrim($issuer, '/'));
        $origin = $server->origin();

        Http::fake([
            $origin.'/.well-known/oauth-authorization-server*' => $server->answerMetadata(...),
            $origin.'/.well-known/openid-configuration*' => $server->answerMetadata(...),
            $server->issuer.'/.well-known/openid-configuration' => $server->answerMetadata(...),
            $server->issuer.'/token' => $server->answerToken(...),
            $server->issuer.'/register' => $server->answerRegistration(...),
        ]);

        return $server;
    }

    public function authorizationEndpoint(): string
    {
        return $this->issuer.'/authorize';
    }

    public function withoutRegistration(): self
    {
        $this->registers = false;

        return $this;
    }

    /**
     * Register clients without a secret, as public clients.
     */
    public function registeringPublicClients(): self
    {
        $this->registersPublicClients = true;

        return $this;
    }

    /**
     * Know a client registered in the server's console.
     */
    public function acceptingClient(string $clientId, ?string $secret = null): self
    {
        $this->clients[$clientId] = $secret;

        return $this;
    }

    public function acceptingMetadataDocuments(): self
    {
        $this->acceptsMetadataDocuments = true;

        return $this;
    }

    public function namingItselfOnReturn(): self
    {
        $this->namesItselfOnReturn = true;

        return $this;
    }

    /**
     * @param  string  $kind  `oauth`, `openid` or `openid-suffix`.
     */
    public function publishingMetadataAt(string $kind): self
    {
        $this->metadataKind = $kind;

        return $this;
    }

    public function namedInMetadataAs(string $issuer): self
    {
        $this->namedIssuer = $issuer;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function withMetadata(array $fields): self
    {
        $this->extraMetadata = [...$this->extraMetadata, ...$fields];

        return $this;
    }

    public function issuingTokensFor(?int $seconds): self
    {
        $this->tokenLifetime = $seconds;

        return $this;
    }

    public function keepingRefreshTokens(): self
    {
        $this->rotatesRefreshTokens = false;

        return $this;
    }

    public function withoutRefreshTokens(): self
    {
        $this->issuesRefreshTokens = false;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function withTokenFields(array $fields): self
    {
        $this->tokenFields = [...$this->tokenFields, ...$fields];

        return $this;
    }

    /**
     * @param  string  $endpoint  `token` or `register`.
     * @param  Closure(Request): PromiseInterface  $responder
     */
    public function respondTo(string $endpoint, Closure $responder): self
    {
        $this->responders[$endpoint] = $responder;

        return $this;
    }

    /**
     * Run the callback when a request to the endpoint arrives, before answering it.
     *
     * @param  string  $endpoint  `token` or `register`.
     * @param  Closure(Request): mixed  $callback
     */
    public function beforeAnswering(string $endpoint, Closure $callback): self
    {
        $this->beforeAnswering[$endpoint] = $callback;

        return $this;
    }

    /**
     * The user approves the client on the sign-in page the URL points to:
     * the URL their browser is sent back to, with a code and the state.
     */
    public function approve(string $authorizationUrl): string
    {
        $query = $this->authorizationQuery($authorizationUrl);
        $clientId = (string) $query['client_id'];

        if (! $this->knowsClient($clientId) || ($query['response_type'] ?? null) !== 'code' || ($query['code_challenge_method'] ?? null) !== 'S256' || ! isset($query['code_challenge'], $query['redirect_uri'])) {
            throw new InvalidArgumentException('The fake authorization server would refuse this sign-in request.');
        }

        $code = 'code-'.Str::random(12);
        $this->codes[$code] = [
            'client_id' => $clientId,
            'redirect_uri' => (string) $query['redirect_uri'],
            'challenge' => (string) $query['code_challenge'],
            'resource' => isset($query['resource']) ? (string) $query['resource'] : null,
        ];

        return $this->callback($query, ['code' => $code]);
    }

    /**
     * The user refuses, or the sign-in page fails: the URL their browser is
     * sent back to, with the error.
     */
    public function deny(string $authorizationUrl, string $error = 'access_denied'): string
    {
        return $this->callback($this->authorizationQuery($authorizationUrl), ['error' => $error, 'error_description' => 'The user said no.']);
    }

    /**
     * Whether an Authorization header carries an access token this server
     * issued that hasn't expired.
     */
    public function accepts(string $authorization): bool
    {
        $token = Str::after($authorization, 'Bearer ');

        if (! str_starts_with($authorization, 'Bearer ') || ! array_key_exists($token, $this->accessTokens)) {
            return false;
        }

        $expiresAt = $this->accessTokens[$token];

        return $expiresAt === null || $expiresAt > now()->getTimestamp();
    }

    /**
     * The token requests received, as their form fields, optionally only
     * those of one grant type.
     *
     * @return list<array<string, mixed>>
     */
    public function tokenRequests(?string $grantType = null): array
    {
        return array_values(array_filter(
            $this->tokenRequests,
            fn (array $fields): bool => $grantType === null || ($fields['grant_type'] ?? null) === $grantType,
        ));
    }

    /**
     * The registration requests received.
     *
     * @return list<array<string, mixed>>
     */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'issuer' => $this->namedIssuer ?? $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint(),
            'token_endpoint' => $this->issuer.'/token',
            ...($this->registers ? ['registration_endpoint' => $this->issuer.'/register'] : []),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            ...($this->acceptsMetadataDocuments ? ['client_id_metadata_document_supported' => true] : []),
            ...($this->namesItselfOnReturn ? ['authorization_response_iss_parameter_supported' => true] : []),
            ...$this->extraMetadata,
        ];
    }

    private function answerMetadata(Request $request): PromiseInterface
    {
        $path = (string) parse_url($this->issuer, PHP_URL_PATH);

        $published = match ($this->metadataKind) {
            'openid' => $this->origin().'/.well-known/openid-configuration'.$path,
            'openid-suffix' => $this->issuer.'/.well-known/openid-configuration',
            default => $this->origin().'/.well-known/oauth-authorization-server'.$path,
        };

        return $request->url() === $published ? Http::response($this->metadata()) : Http::response('Not found', 404);
    }

    private function answerRegistration(Request $request): PromiseInterface
    {
        $fields = json_decode($request->body(), true);
        $this->registrations[] = is_array($fields) ? $fields : [];

        if (isset($this->beforeAnswering['register'])) {
            ($this->beforeAnswering['register'])($request);
        }

        if (isset($this->responders['register'])) {
            return ($this->responders['register'])($request);
        }

        if (! $this->registers) {
            return Http::response('Not found', 404);
        }

        $clientId = 'registered-client-'.(count($this->registrations));
        $secret = $this->registersPublicClients ? null : 'registered-secret-'.(count($this->registrations));
        $this->clients[$clientId] = $secret;

        return Http::response(array_filter([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'token_endpoint_auth_method' => $secret === null ? 'none' : ($fields['token_endpoint_auth_method'] ?? 'client_secret_basic'),
            'redirect_uris' => $fields['redirect_uris'] ?? [],
        ]), 201);
    }

    private function answerToken(Request $request): PromiseInterface
    {
        parse_str($request->body(), $fields);
        $basic = $request->header('Authorization')[0] ?? null;

        if (is_string($basic) && str_starts_with($basic, 'Basic ')) {
            [$fields['client_id'], $fields['client_secret']] = explode(':', (string) base64_decode(substr($basic, 6), true), 2) + [1 => ''];
            $fields['basic_auth'] = true;
        }

        $this->tokenRequests[] = $fields;

        if (isset($this->beforeAnswering['token'])) {
            ($this->beforeAnswering['token'])($request);
        }

        if (isset($this->responders['token'])) {
            return ($this->responders['token'])($request);
        }

        $clientId = is_string($fields['client_id'] ?? null) ? $fields['client_id'] : '';

        if (! $this->knowsClient($clientId) || ($this->clients[$clientId] ?? null) !== (is_string($fields['client_secret'] ?? null) ? $fields['client_secret'] : null)) {
            return $this->tokenError('invalid_client', 401);
        }

        return match ($fields['grant_type'] ?? null) {
            'authorization_code' => $this->exchangeCode($fields, $clientId),
            'refresh_token' => $this->renew($fields, $clientId),
            default => $this->tokenError('unsupported_grant_type'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function exchangeCode(array $fields, string $clientId): PromiseInterface
    {
        $code = $this->codes[is_string($fields['code'] ?? null) ? $fields['code'] : ''] ?? null;
        unset($this->codes[(string) ($fields['code'] ?? '')]);

        $verifier = is_string($fields['code_verifier'] ?? null) ? $fields['code_verifier'] : '';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($code === null || $code['client_id'] !== $clientId || $code['redirect_uri'] !== ($fields['redirect_uri'] ?? null)
            || $code['challenge'] !== $challenge || $code['resource'] !== ($fields['resource'] ?? null)) {
            return $this->tokenError('invalid_grant');
        }

        return $this->issueTokens($clientId);
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function renew(array $fields, string $clientId): PromiseInterface
    {
        $refreshToken = is_string($fields['refresh_token'] ?? null) ? $fields['refresh_token'] : '';

        if (($this->refreshTokens[$refreshToken] ?? null) !== $clientId) {
            return $this->tokenError('invalid_grant');
        }

        if (! $this->rotatesRefreshTokens) {
            return $this->issueTokens($clientId, withRefreshToken: false);
        }

        unset($this->refreshTokens[$refreshToken]);

        return $this->issueTokens($clientId);
    }

    private function issueTokens(string $clientId, bool $withRefreshToken = true): PromiseInterface
    {
        $number = ++$this->issued;
        $accessToken = "access-token-{$number}";
        $this->accessTokens[$accessToken] = $this->tokenLifetime === null ? null : now()->getTimestamp() + $this->tokenLifetime;

        $response = ['access_token' => $accessToken, 'token_type' => 'Bearer', ...$this->tokenFields];

        if ($this->tokenLifetime !== null) {
            $response['expires_in'] = $this->tokenLifetime;
        }

        if ($withRefreshToken && $this->issuesRefreshTokens) {
            $response['refresh_token'] = "refresh-token-{$number}";
            $this->refreshTokens[$response['refresh_token']] = $clientId;
        }

        return Http::response($response);
    }

    private function tokenError(string $error, int $status = 400): PromiseInterface
    {
        return Http::response(['error' => $error, 'error_description' => 'Secret server details: user 42 at db-7.'], $status);
    }

    private function knowsClient(string $clientId): bool
    {
        return array_key_exists($clientId, $this->clients) || ($this->acceptsMetadataDocuments && str_starts_with($clientId, 'https://'));
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationQuery(string $authorizationUrl): array
    {
        if (Str::before($authorizationUrl, '?') !== $this->authorizationEndpoint()) {
            throw new InvalidArgumentException("[{$authorizationUrl}] isn't this server's sign-in page.");
        }

        return Uri::of($authorizationUrl)->query()->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $answer
     */
    private function callback(array $query, array $answer): string
    {
        return (string) Uri::of((string) $query['redirect_uri'])->withQuery([
            ...$answer,
            'state' => $query['state'] ?? null,
            ...($this->namesItselfOnReturn ? ['iss' => $this->namedIssuer ?? $this->issuer] : []),
        ]);
    }

    private function origin(): string
    {
        $parts = parse_url($this->issuer);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }
}
