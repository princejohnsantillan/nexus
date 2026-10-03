<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Livewire\Livewire;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeAuthorizationServer;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->star = Star::factory()->for($this->user)->create(['name' => 'Work']);
});

/**
 * The Connections page's catalog, adding to the Star.
 */
function addingTo(Star $star): string
{
    return route('connections.index', ['star' => $star->public_id]).'#add-more';
}

/**
 * The names of the Star's tools that are on.
 *
 * @return list<string>
 */
function toolsOnIn(Star $star): array
{
    $star->touch();

    return array_map(fn (StarTool $tool): string => $tool->name, app(StarToolset::class)->enabledTools($star));
}

it('links "Add a connection" on the Star\'s overview to the catalog, with the Star to come back to', function (): void {
    Connection::factory()->for($this->user)->create();

    Livewire::test('pages::stars.show', ['star' => $this->star])
        ->assertSeeText('Add a connection')
        ->assertSeeText('you\'ll come back here')
        ->assertSeeHtml('href="'.addingTo($this->star).'"')
        ->assertSeeHtml('id="setup"');
});

it('says on the Connections page which Star the user is adding to, with a way back to it that adds nothing', function (): void {
    $this->get(addingTo($this->star))
        ->assertOk()
        ->assertSeeText('Adding to Work. You\'ll go back to Work when it\'s connected.')
        ->assertSeeHtml('href="'.route('stars.show', $this->star).'"');

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->assertSet('returnTo', $this->star->public_id)
        ->assertSeeHtml('data-adding-to-star-cancel')
        ->call('startConnecting', 'notion')
        ->assertSeeText('Adding to Work. You\'ll go back to Work when it\'s connected.')
        ->assertSeeText('Read-only tools start on in Work.')
        ->assertSeeText('then loads the tools and brings you back to Work.');

    expect($this->star->connections()->count())->toBe(0);
});

it('says which tools start on in the Star, by its new-tool policy', function (NewToolPolicy $policy, string $hint): void {
    $this->star->update(['new_tool_policy' => $policy]);

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->call('startConnecting', 'notion')
        ->assertSeeText($hint);
})->with([
    'read-only on' => [NewToolPolicy::ReadOnly, 'Read-only tools start on in Work.'],
    'all on' => [NewToolPolicy::All, 'Every tool starts on in Work.'],
    'all off' => [NewToolPolicy::None, 'Every tool starts off in Work.'],
]);

it('connects GitHub with a token and goes back to the Star with it added and saved', function (): void {
    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')
        ->requireHeader('Authorization', 'Bearer github_pat_good')
        ->withTools([['name' => 'search_issues', 'annotations' => ['readOnlyHint' => true]], ['name' => 'create_issue']]);

    $component = Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('method', 'token')
        ->set('token', 'github_pat_good')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertRedirect(route('stars.show', $this->star));

    $connection = $this->user->connections()->sole();

    expect($this->star->connections()->pluck('connections.id')->all())->toBe([$connection->id])
        ->and(toolsOnIn($this->star))->toBe(['github__search_issues']);

    $this->get(route('stars.show', $this->star))
        ->assertSeeText('GitHub added to Work.')
        ->assertDontSeeText('Unsaved changes')
        ->assertDontSeeText('Not saved');

    Livewire::test('pages::stars.show', ['star' => $this->star])
        ->assertSet('connectionIds', [(string) $connection->id])
        ->assertSet('hasUnsavedChanges', false);
});

it('keeps the user on the Connections page, still adding to the Star, when the server refuses the token', function (): void {
    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->requireHeader('Authorization', 'Bearer github_pat_good');

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('method', 'token')
        ->set('token', 'github_pat_bad')
        ->call('connect')
        ->assertHasErrors(['token'])
        ->assertNoRedirect()
        ->assertSeeText('Adding to Work. You\'ll go back to Work when it\'s connected.');

    expect(Connection::query()->count())->toBe(0)
        ->and($this->star->connections()->count())->toBe(0);
});

it('signs Notion in on its consent screen and goes back to the Star with it added', function (): void {
    $authorizationServer = FakeAuthorizationServer::at('https://mcp.notion.com')->acceptingMetadataDocuments();
    FakeMcpServer::at('https://mcp.notion.com/mcp')->requireOAuth($authorizationServer, scopesSupported: ['default'])
        ->withTools([['name' => 'notion-search', 'annotations' => ['readOnlyHint' => true]]]);

    $component = Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->call('startConnecting', 'notion')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $start = route('connections.connect', ['connection' => $connection, 'star' => $this->star->public_id]);
    $component->assertRedirect($start);

    $consent = (string) $this->get($start)->assertRedirect()->headers->get('Location');
    $callback = $authorizationServer->approve($consent);

    expect($callback)->not->toContain($this->star->public_id);

    $this->get($callback)
        ->assertRedirect(route('stars.show', $this->star))
        ->assertSessionHas('toast', ['variant' => 'success', 'text' => 'Notion added to Work.']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($this->star->connections()->pluck('connections.id')->all())->toBe([$connection->id])
        ->and(toolsOnIn($this->star))->toBe(['notion__notion-search']);
});

it('keeps the user on the Connections page with the error, still adding to the Star, when they don\'t approve Nexus', function (): void {
    $authorizationServer = FakeAuthorizationServer::at('https://mcp.notion.com')->acceptingMetadataDocuments();
    FakeMcpServer::at('https://mcp.notion.com/mcp')->requireOAuth($authorizationServer, scopesSupported: ['default']);
    $connection = Connection::factory()->for($this->user)->fromConnector('notion')->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);

    $consent = (string) $this->get(route('connections.connect', ['connection' => $connection, 'star' => $this->star->public_id]))->headers->get('Location');

    $this->get($authorizationServer->deny($consent))
        ->assertRedirect(addingTo($this->star))
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => 'You didn\'t approve Nexus, so it isn\'t signed in.']);

    $this->get(addingTo($this->star))
        ->assertSeeText('You didn\'t approve Nexus, so it isn\'t signed in.')
        ->assertSeeText('Adding to Work. You\'ll go back to Work when it\'s connected.');

    expect($this->star->connections()->count())->toBe(0);
});

it('keeps the user on the Connections page, still adding to the Star, when signing in can\'t start', function (): void {
    FakeMcpServer::at();
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    $this->get(route('connections.connect', ['connection' => $connection, 'star' => $this->star->public_id]))
        ->assertRedirect(addingTo($this->star))
        ->assertSessionHas('toast.variant', 'danger');

    expect($this->star->connections()->count())->toBe(0);
});

it('adds a custom server without sign-in and goes back to the Star', function (): void {
    FakeMcpServer::at('https://mcp.deepwiki.com/mcp')->withTools([['name' => 'ask_question', 'annotations' => ['readOnlyHint' => true]]]);

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.add-custom')
        ->assertSeeText('Adding to Work. You\'ll go back to Work when it\'s connected.')
        ->assertSeeText('Read-only tools start on in Work.')
        ->assertSeeHtml('href="'.addingTo($this->star).'"')
        ->set('name', 'DeepWiki')
        ->set('handle', 'deepwiki')
        ->set('url', 'https://mcp.deepwiki.com/mcp')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('stars.show', $this->star));

    $connection = $this->user->connections()->sole();

    expect($this->star->connections()->pluck('connections.id')->all())->toBe([$connection->id])
        ->and(toolsOnIn($this->star))->toBe(['deepwiki__ask_question']);

    $this->get(route('stars.show', $this->star))->assertSeeText('DeepWiki added to Work.');
});

it('adds a custom server signed in with a header and goes back to the Star', function (): void {
    $server = FakeMcpServer::at()->requireHeader('X-Api-Key', 'sk-live-123')->withTools([['name' => 'search']]);

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.add-custom')
        ->set('name', 'Acme')
        ->set('handle', 'acme')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->set('authType', 'header')
        ->set('headerName', 'X-Api-Key')
        ->set('headerValue', 'sk-live-123')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('stars.show', $this->star));

    expect($this->star->connections()->pluck('handle')->all())->toBe(['acme'])
        ->and(session('toast'))->toBe(['variant' => 'success', 'text' => 'Acme added to Work.'])
        ->and($server->requests())->not->toBe([]);
});

it('adds a custom server whose tools didn\'t load to the Star, and says so', function (): void {
    FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::error(-32603, 'Broken'));

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.add-custom')
        ->set('name', 'Broken')
        ->set('handle', 'broken')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->call('save')
        ->assertRedirect(route('stars.show', $this->star));

    expect($this->star->connections()->pluck('handle')->all())->toBe(['broken'])
        ->and(session('toast'))->toBe(['variant' => 'warning', 'text' => 'Broken added to Work, but Nexus couldn\'t load its tools. Its page says why.']);
});

it('signs a custom OAuth server in and goes back to the Star with it added', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);

    $component = Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.add-custom')
        ->set('name', 'Acme')
        ->set('handle', 'acme')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->set('authType', 'oauth')
        ->call('save')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $start = route('connections.connect', ['connection' => $connection, 'star' => $this->star->public_id]);
    $component->assertRedirect($start);

    $consent = (string) $this->get($start)->headers->get('Location');

    $this->get($server->authorizationServer()->approve($consent))
        ->assertRedirect(route('stars.show', $this->star))
        ->assertSessionHas('toast', ['variant' => 'success', 'text' => 'Acme added to Work.']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($this->star->connections()->pluck('connections.id')->all())->toBe([$connection->id]);
});

it('goes back to the Star from Cancel on the custom server page, adding nothing', function (): void {
    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.add-custom')
        ->assertSeeHtml('href="'.route('stars.show', $this->star).'"')
        ->assertDontSeeHtml('href="'.route('connections.index').'#add-more"');

    expect(Connection::query()->count())->toBe(0);
});

it('ignores a Star that isn\'t the user\'s own, or doesn\'t exist', function (Closure $publicId): void {
    $publicId = $publicId();
    FakeMcpServer::at('https://mcp.deepwiki.com/mcp')->withTools([['name' => 'ask_question']]);

    $this->get(route('connections.index', ['star' => $publicId]))
        ->assertOk()
        ->assertDontSeeText('Adding to');

    $component = Livewire::withQueryParams(['star' => $publicId])->test('pages::connections.add-custom')
        ->assertSet('returnTo', null)
        ->assertDontSeeText('Adding to')
        ->set('name', 'DeepWiki')
        ->set('handle', 'deepwiki')
        ->set('url', 'https://mcp.deepwiki.com/mcp')
        ->call('save');

    $connection = $this->user->connections()->sole();
    $component->assertRedirect(route('connections.show', $connection));

    expect(Star::query()->whereHas('connections')->count())->toBe(0);
})->with([
    'another user\'s Star' => [fn (): string => Star::factory()->create()->public_id],
    'a Star that doesn\'t exist' => [Star::newPublicId(...)],
    'not a Star id' => [fn (): string => '../stars'],
]);

it('ignores another user\'s Star given to the sign-in, which goes on to the Connection as usual', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $theirs = Star::factory()->create();

    $consent = (string) $this->get(route('connections.connect', ['connection' => $connection, 'star' => $theirs->public_id]))->headers->get('Location');

    $this->get($server->authorizationServer()->approve($consent))
        ->assertRedirect(route('connections.show', $connection));

    expect($theirs->connections()->count())->toBe(0);
});

it('goes back only to a Star the sign-in started with, never one named on the way back', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    $consent = ConnectionOAuthFlow::start($this, $connection);

    $this->get($server->authorizationServer()->approve($consent).'&star='.$this->star->public_id)
        ->assertRedirect(route('connections.show', $connection));

    expect($this->star->connections()->count())->toBe(0);
});

it('signs in a Connection in no Star yet from the Connections page and adds it to the Star', function (): void {
    $new = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Acme']);
    $used = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Elsewhere']);
    Star::factory()->for($this->user)->including($used)->create();

    Livewire::withQueryParams(['star' => $this->star->public_id])->test('pages::connections.index')
        ->assertSeeHtml('href="'.e(route('connections.connect', ['connection' => $new, 'star' => $this->star->public_id])).'"')
        ->assertSeeHtml('href="'.route('connections.connect', $used).'"');
});

it('previews the tool names agents will see, saying the handle can\'t change', function (): void {
    $github = Connection::factory()->for($this->user)->fromConnector('github')->create(['handle' => 'github']);
    ConnectionTool::factory()->for($github)->create(['name' => 'search_issues']);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertSet('handle', 'github-2')
        ->assertSeeTextInOrder(['Agents will see', 'github-2__search_issues', 'can\'t change later'])
        ->set('handle', 'github-acme')
        ->assertSeeText('github-acme__search_issues');

    Livewire::test('pages::connections.add-custom')
        ->assertSeeTextInOrder(['Agents will see', 'handle__<tool>', 'can\'t change later'])
        ->set('handle', 'deepwiki')
        ->assertSeeText('deepwiki__<tool>');
});
