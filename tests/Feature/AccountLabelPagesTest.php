<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->github = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_work')->connected()
        ->create(['name' => 'GitHub', 'handle' => 'github', 'account_identity' => 'octocat', 'description' => 'work repositories']);
    ConnectionTool::factory()->for($this->github)->create(['name' => 'get_me']);
});

/**
 * Match the Flux toast with this text.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function toastReading(string $text): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text;
}

/**
 * GitHub's MCP server for this token, answering `get_me` as the login.
 */
function gitHubServerFor(string $token, string $login): FakeMcpServer
{
    return FakeMcpServer::at('https://api.githubcopilot.com/mcp/')
        ->requireHeader('Authorization', "Bearer {$token}")
        ->withTools([['name' => 'get_me']])
        ->onCall('get_me', fn (): array => ['content' => [['type' => 'text', 'text' => json_encode(['login' => $login])]]]);
}

it('shows each Connection\'s account and "use for" note in the Connections list', function (): void {
    Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['DeepWiki', 'deepwiki', 'GitHub', 'octocat', 'Use for: work repositories', 'github']);
});

it('shows the account on every page of a Connection, and on its overview', function (string $route): void {
    $response = $this->get(route($route, $this->github))
        ->assertOk()
        ->assertSeeTextInOrder(['GitHub', 'github', 'octocat', 'Use for: work repositories', 'Overview', 'Tools']);

    if ($route === 'connections.show') {
        $response->assertSeeTextInOrder(['Sign-in', 'Your own token', 'Account', 'octocat', 'Tools', '1 tool']);
    }
})->with(['connections.show', 'connections.tools', 'connections.prompts']);

it('says when it doesn\'t know which account a Connection signed in as', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeTextInOrder(['Account', 'Not detected. Nexus names the account when the server says which one it is.', 'Tools'])
        ->assertDontSeeText('Signed in as');
});

it('shows the accounts in the Star Connection pickers', function (string $route): void {
    $star = Star::factory()->for($this->user)->including($this->github)->create();

    $this->get($route === 'stars.index' ? route($route) : route($route, $star))
        ->assertOk()
        ->assertSeeTextInOrder(['GitHub', 'github', '1 tool', 'octocat', 'Use for: work repositories']);
})->with(['stars.index', 'stars.show']);

it('shows each Connection\'s account on a Star\'s Tools page', function (): void {
    $star = Star::factory()->for($this->user)->including($this->github)->create();

    $this->get(route('stars.tools', $star))
        ->assertOk()
        ->assertSeeTextInOrder(['GitHub', 'github', 'octocat', 'Use for: work repositories', '0 of 1 on', 'github__get_me']);
});

it('escapes the account a server named', function (): void {
    $this->github->forceFill(['account_identity' => '<img src=x onerror=alert(1)>'])->save();

    $this->get(route('connections.show', $this->github))
        ->assertOk()
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', escape: false)
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
});

it('says that refreshing reloads only this account when the user has other accounts of the service', function (): void {
    gitHubServerFor('github_pat_work', 'octocat');
    Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub 2', 'handle' => 'github-2']);
    Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub 3', 'handle' => 'github-3']);

    Livewire::test('pages::connections.show', ['connection' => $this->github])
        ->assertSeeText('Refresh tools reloads only this account. GitHub 2 and GitHub 3 are other accounts of the same service, each with its own Refresh tools.')
        ->call('refreshTools')
        ->assertDispatched('toast-show', toastReading('Nexus loaded 1 tool. Only this account was refreshed, not GitHub 2 and GitHub 3.'));
});

it('counts only the user\'s own Connections of the same service as other accounts', function (): void {
    gitHubServerFor('github_pat_work', 'octocat');
    Connection::factory()->fromConnector('github')->create(['name' => 'Someone else\'s GitHub']);
    Connection::factory()->for($this->user)->fromConnector('linear')->create(['name' => 'Linear']);
    Connection::factory()->for($this->user)->create(['name' => 'Custom', 'url' => 'https://api.githubcopilot.com/mcp/']);

    Livewire::test('pages::connections.show', ['connection' => $this->github])
        ->assertDontSeeText('reloads only this account')
        ->call('refreshTools')
        ->assertDispatched('toast-show', toastReading('Nexus loaded 1 tool.'));
});

it('treats custom servers on the same host as accounts of one service', function (): void {
    FakeMcpServer::at('https://mcp.deepwiki.com/mcp')->withTools([['name' => 'ask_question']]);
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'url' => 'https://mcp.deepwiki.com/mcp']);
    Connection::factory()->for($this->user)->create(['name' => 'DeepWiki 2', 'url' => 'https://mcp.deepwiki.com/sse']);

    Livewire::test('pages::connections.show', ['connection' => $wiki])
        ->assertSeeText('DeepWiki 2 is another account of the same service, with its own Refresh tools.')
        ->call('refreshTools')
        ->assertDispatched('toast-show', toastReading('Nexus loaded 1 tool. Only this account was refreshed, not DeepWiki 2.'));
});

it('labels a GitHub Connection with the login of a replaced token', function (): void {
    gitHubServerFor('github_pat_personal', 'hubot');

    Livewire::test('pages::connections.show', ['connection' => $this->github])
        ->set('token', 'github_pat_personal')
        ->call('replaceToken')
        ->assertHasNoErrors()
        ->assertSeeTextInOrder(['github', 'hubot', 'Use for: work repositories']);

    expect($this->github->refresh()->account_identity)->toBe('hubot');
});

it('forgets the account when a replaced token\'s tools don\'t load, since it may be another account', function (): void {
    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->requireHeader('Authorization', 'Bearer github_pat_other');

    Livewire::test('pages::connections.show', ['connection' => $this->github])
        ->set('token', 'github_pat_personal')
        ->call('replaceToken');

    expect($this->github->refresh()->account_identity)->toBeNull();
});

it('forgets the account when a custom server moves to another URL', function (): void {
    FakeMcpServer::at('https://mcp.elsewhere.example.com/mcp')->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->create(['account_identity' => 'ada@example.com']);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'https://mcp.elsewhere.example.com/mcp')
        ->call('saveServer')
        ->assertHasNoErrors();

    expect($connection->refresh()->account_identity)->toBeNull();
});

it('keeps the account when a custom server\'s form is saved unchanged', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->create(['account_identity' => 'ada@example.com']);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('saveServer')
        ->assertHasNoErrors();

    expect($connection->refresh()->account_identity)->toBe('ada@example.com');
});
