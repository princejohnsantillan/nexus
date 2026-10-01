<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;

/**
 * The start of GitHub's logo as the pages inline it, at a size.
 */
function githubLogo(string $size): string
{
    return '<svg aria-hidden="true" focusable="false" class="'.$size.'" xmlns="http://www.w3.org/2000/svg" viewBox="0 -1 98 98">';
}

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('shows each Connection\'s connector logo in the list, and a server icon for a custom server', function (): void {
    Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub', 'handle' => 'github']);
    Connection::factory()->for($this->user)->fromConnector('linear')->create(['name' => 'Linear', 'handle' => 'linear']);
    Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);

    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSee(githubLogo('size-4'), escape: false)
        ->assertSee('<svg aria-hidden="true" focusable="false" class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">', escape: false)
        ->assertSee('data-flux-icon', escape: false);
});

it('falls back to the server icon for a connector that is no longer in the gallery', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['connector_key' => 'retired']);

    expect($connection->connector())->toBeNull();

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeText('Server and sign-in');
});

it('shows the connector logo on every page of a Connection', function (string $route): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_old')->create();

    $this->get(route($route, $connection))
        ->assertOk()
        ->assertSee(githubLogo('size-6'), escape: false);
})->with(['connections.show', 'connections.tools', 'connections.prompts']);

it('describes a token sign-in and offers to replace the token instead of changing the server', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_old')->connected()->create();

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeTextInOrder(['Sign-in', 'Your own token', 'Authorization'])
        ->assertSeeText('Replace the token Nexus signs in to GitHub with, for example when it expires.')
        ->assertSee('https://github.com/settings/personal-access-tokens/new')
        ->assertDontSeeText('Server and sign-in')
        ->assertDontSee('github_pat_old');
});

it('replaces the token, sent with its prefix, and reloads the tools with it', function (): void {
    $server = FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->requireHeader('Authorization', 'Bearer github_pat_new')->withTools([['name' => 'get_me']]);
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_old')->failed()->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('token', 'github_pat_new')
        ->call('replaceToken')
        ->assertHasNoErrors()
        ->assertSet('token', '')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Token replaced. Nexus loaded 1 tool.');

    expect($connection->refresh()->headerValue())->toBe('Bearer github_pat_new')
        ->and($connection->url)->toBe('https://api.githubcopilot.com/mcp/')
        ->and($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($server->requests())->not->toBeEmpty();
});

it('requires a new token to replace it', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_old')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('replaceToken')
        ->assertHasErrors(['token' => 'required']);

    expect($connection->refresh()->headerValue())->toBe('Bearer github_pat_old');
});

it('keeps a connector\'s server fixed', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader('Bearer github_pat_old')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'https://mcp.example.com/mcp')
        ->call('saveServer')
        ->assertNotFound();

    expect($connection->refresh()->url)->toBe('https://api.githubcopilot.com/mcp/')
        ->and($connection->headerValue())->toBe('Bearer github_pat_old');
});

it('has no token to replace on a custom server', function (): void {
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-123')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->assertDontSeeText('Replace the token')
        ->set('token', 'sk-live-456')
        ->call('replaceToken')
        ->assertNotFound();

    expect($connection->refresh()->headerValue())->toBe('Bearer sk-live-123');
});

it('shows connector logos in the Star Connection pickers', function (string $route): void {
    $github = Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub']);
    Connection::factory()->for($this->user)->create(['name' => 'Wiki']);
    $star = Star::factory()->for($this->user)->including($github)->create();

    $this->get($route === 'stars.index' ? route($route) : route($route, $star))
        ->assertOk()
        ->assertSeeInOrder([githubLogo('size-4'), 'GitHub', 'Wiki'], escape: false);
})->with(['stars.index', 'stars.show']);

it('shows each Connection\'s logo in the Stars list and on a Star\'s Tools page', function (): void {
    $github = Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub']);
    $star = Star::factory()->for($this->user)->including($github)->create();

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee(githubLogo('size-3.5'), escape: false);

    $this->get(route('stars.tools', $star))
        ->assertOk()
        ->assertSeeInOrder([githubLogo('size-4'), 'GitHub'], escape: false);
});
