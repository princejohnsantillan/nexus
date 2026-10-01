<?php

declare(strict_types=1);

use App\Actions\DetectAccountIdentity;
use App\Actions\RefreshCatalog;
use App\Models\Connection;
use Tests\Support\FakeMcpServer;

/**
 * An OpenID Connect ID token carrying these claims, with a signature
 * nothing checks.
 *
 * @param  array<string, mixed>  $claims
 */
function idTokenClaiming(array $claims): string
{
    $encode = fn (string $part): string => rtrim(strtr(base64_encode($part), '+/', '-_'), '=');

    return $encode('{"alg":"RS256"}').'.'.$encode((string) json_encode($claims)).'.signature';
}

/**
 * GitHub's MCP server, listing these tools and answering `get_me` as the account.
 *
 * @param  array<array-key, mixed>|null  $profile  What `get_me` answers; null when it isn't listed.
 */
function gitHubServerAnswering(?array $profile): FakeMcpServer
{
    $tools = [['name' => 'search_issues', 'inputSchema' => ['type' => 'object']]];

    if ($profile !== null) {
        $tools[] = ['name' => 'get_me', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass], 'annotations' => ['readOnlyHint' => true]];
    }

    return FakeMcpServer::at('https://api.githubcopilot.com/mcp/')
        ->withTools($tools)
        ->onCall('get_me', fn (): array => $profile ?? []);
}

/**
 * A tool result carrying the object as JSON in its text, as GitHub's `get_me` answers.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function textResultOf(array $object): array
{
    return ['content' => [['type' => 'text', 'text' => json_encode($object)]]];
}

describe('token responses', function (): void {
    it('names the account an OAuth token response says was signed in to', function (array $response, ?string $identity): void {
        expect(resolve(DetectAccountIdentity::class)->fromTokenResponse(['access_token' => 'secret-token', 'token_type' => 'Bearer', ...$response]))
            ->toBe($identity);
    })->with([
        'Notion\'s workspace' => [['workspace_name' => 'BetterWorld', 'workspace_id' => 'w-1', 'bot_id' => 'b-1'], 'BetterWorld'],
        'Slack\'s team' => [['team' => ['id' => 'T1', 'name' => 'BetterWorld']], 'BetterWorld'],
        'an ID token\'s email' => [['id_token' => idTokenClaiming(['sub' => '42', 'email' => 'ada@example.com'])], 'ada@example.com'],
        'a person and a workspace' => [['user' => ['email' => 'ada@example.com'], 'team' => ['name' => 'BetterWorld']], 'ada@example.com @ BetterWorld'],
        'nothing' => [['expires_in' => 3600, 'refresh_token' => 'refresh'], null],
        'blank fields' => [['workspace_name' => '  ', 'email' => ''], null],
        'a field that isn\'t text' => [['workspace_name' => ['BetterWorld'], 'login' => 42], null],
        'an ID token that isn\'t a JWT' => [['id_token' => 'opaque'], null],
    ]);

    it('flattens what the server sent to one line of at most 100 characters', function (): void {
        $identity = resolve(DetectAccountIdentity::class)->fromTokenResponse(['workspace_name' => " Better\nWorld\u{200B}\t".str_repeat('x', 200)]);

        expect($identity)->toStartWith('Better World xxx')->toHaveLength(100)->toEndWith('x…');
    });
});

describe('profile tools', function (): void {
    it('labels a GitHub Connection with the login its profile tool names, calling it with no arguments', function (): void {
        $server = gitHubServerAnswering(textResultOf(['login' => 'octocat', 'id' => 583231, 'details' => ['name' => 'The Octocat']]));
        $connection = Connection::factory()->fromConnector('github')->withHeader('Bearer github_pat_test')->create();

        $loaded = resolve(RefreshCatalog::class)->handle($connection);

        $calls = $server->received('tools/call');

        expect($loaded)->toBeTrue()
            ->and($connection->refresh()->account_identity)->toBe('octocat')
            ->and($connection->tools()->pluck('name')->all())->toBe(['get_me', 'search_issues'])
            ->and($calls)->toHaveCount(1)
            ->and($calls[0]->params->name)->toBe('get_me')
            ->and($calls[0]->params->arguments)->toEqual(new stdClass);
    });

    it('reads the login from the result\'s structured content', function (): void {
        gitHubServerAnswering(['content' => [['type' => 'text', 'text' => 'Signed in.']], 'structuredContent' => ['login' => 'hubot']]);
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create();

        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->refresh()->account_identity)->toBe('hubot');
    });

    it('updates the login at every refresh', function (): void {
        gitHubServerAnswering(textResultOf(['login' => 'hubot']));
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create(['account_identity' => 'octocat']);

        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->refresh()->account_identity)->toBe('hubot');
    });

    it('calls no tool when the server doesn\'t list the profile tool', function (): void {
        $server = gitHubServerAnswering(null);
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create(['account_identity' => 'octocat']);

        resolve(RefreshCatalog::class)->handle($connection);

        expect($server->received('tools/call'))->toBe([])
            ->and($connection->refresh()->account_identity)->toBe('octocat');
    });

    it('never calls a custom server\'s tools, whatever their names', function (): void {
        $server = FakeMcpServer::at()->withTools([['name' => 'get_me'], ['name' => 'whoami']]);
        $connection = Connection::factory()->create();

        resolve(RefreshCatalog::class)->handle($connection);

        expect($server->received('tools/call'))->toBe([])
            ->and($connection->refresh()->account_identity)->toBeNull();
    });

    it('keeps the account it knew, and still loads the tools, when the profile tool doesn\'t say who it is', function (Closure $answer): void {
        $answer(gitHubServerAnswering(textResultOf(['login' => 'hubot'])));
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create(['account_identity' => 'octocat']);

        expect(resolve(RefreshCatalog::class)->handle($connection))->toBeTrue()
            ->and($connection->refresh()->account_identity)->toBe('octocat')
            ->and($connection->tools()->count())->toBe(2);
    })->with([
        'a tool error' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('get_me', fn (): array => [...textResultOf(['login' => 'hubot']), 'isError' => true]),
        'a JSON-RPC error' => fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/call', FakeMcpServer::error(-32603)),
        'a timeout' => fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/call', FakeMcpServer::timeout()),
        'no login' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('get_me', fn (): array => textResultOf(['id' => 583231])),
        'a login that isn\'t text' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('get_me', fn (): array => textResultOf(['login' => ['octocat']])),
        'text that isn\'t JSON' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('get_me', fn (): array => ['content' => [['type' => 'text', 'text' => 'You are hubot.']]]),
    ]);

    it('flattens the login to one line of at most 100 characters', function (): void {
        gitHubServerAnswering(textResultOf(['login' => "octo\ncat\u{202E}".str_repeat('x', 150)]));
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create();

        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->refresh()->account_identity)->toStartWith('octo cat xxx')->toHaveLength(100)->toEndWith('…');
    });

    it('doesn\'t label a Connection whose server changed while its profile tool answered', function (): void {
        $server = gitHubServerAnswering(textResultOf(['login' => 'hubot']));
        $connection = Connection::factory()->fromConnector('github')->withHeader()->create(['account_identity' => 'octocat']);
        $server->beforeAnswering('tools/call', function () use ($connection): void {
            Connection::query()->whereKey($connection->id)->update(['url' => 'https://elsewhere.example.com/mcp']);
        });

        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->refresh()->account_identity)->toBe('octocat');
    });
});
