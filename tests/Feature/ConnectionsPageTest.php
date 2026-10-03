<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

/**
 * The HTML of one card in the catalog, from its key to the next card.
 */
function catalogCard(string $html, string $key): string
{
    return Str::before(Str::after($html, "data-connector=\"{$key}\""), 'data-connector=');
}

it('shows an empty state that leads to the catalog below it', function (): void {
    Livewire::test('pages::connections.index')
        ->assertOk()
        ->assertSeeText('No Connections yet')
        ->assertSeeText('Add your first connection')
        ->assertSeeHtml('href="#add-more"')
        ->assertSeeHtml('id="add-more"')
        ->assertSeeTextInOrder(['Connect a server', 'GitHub', 'Linear', 'Notion', 'Custom server']);
});

it('lists the user\'s Connections with their account, handle, tool count, status and last refresh', function (): void {
    $this->travelTo(now()->subHours(3), function (): void {
        $deepwiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'deepwiki', 'description' => 'reading docs', 'url' => 'https://mcp.deepwiki.com/mcp']);
        ConnectionTool::factory()->for($deepwiki)->count(3)->create();
    });
    Connection::factory()->for($this->user)->failed()->create(['name' => 'Broken', 'handle' => 'broken']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['Broken', 'broken', '0', 'Error', 'Tools never loaded'])
        ->assertSeeTextInOrder(['DeepWiki', 'Custom server · mcp.deepwiki.com', 'Use for: reading docs', 'deepwiki', '3', 'Connected', 'Tools refreshed 3 hours ago'])
        ->assertSee(route('connections.show', Connection::query()->where('handle', 'deepwiki')->sole()));
});

it('shows only the user\'s own Connections', function (): void {
    Connection::factory()->create(['name' => 'Someone else\'s server']);

    Livewire::test('pages::connections.index')
        ->assertSeeText('No Connections yet')
        ->assertDontSeeText('Someone else\'s server');
});

it('shows the Stars that use each Connection, linking to them', function (): void {
    $github = Connection::factory()->for($this->user)->create(['name' => 'GitHub', 'handle' => 'github']);
    Connection::factory()->for($this->user)->create(['name' => 'Linear', 'handle' => 'linear']);
    $work = Star::factory()->for($this->user)->including($github)->create(['name' => 'Work']);
    $research = Star::factory()->for($this->user)->including($github)->create(['name' => 'Research']);
    $personal = Star::factory()->for($this->user)->create(['name' => 'Personal']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['GitHub', 'Research', 'Work', 'Linear', 'Not in any Star'])
        ->assertSee(route('stars.show', $work))
        ->assertSee(route('stars.show', $research))
        ->assertDontSee(route('stars.show', $personal));
});

it('links to every Star that uses a Connection, beyond the two shown as chips', function (): void {
    $github = Connection::factory()->for($this->user)->create(['name' => 'GitHub']);
    $stars = collect(['Alpha', 'Beta', 'Gamma', 'Delta'])
        ->map(fn (string $name): Star => Star::factory()->for($this->user)->including($github)->create(['name' => $name]));

    $component = Livewire::test('pages::connections.index')
        ->assertSeeText('+2')
        ->assertSeeHtml('aria-label="Show 2 more Stars"');

    foreach ($stars as $star) {
        $component->assertSee(route('stars.show', $star));
    }
});

it('says why a Connection needs sign-in and offers to reconnect it', function (): void {
    $notion = Connection::factory()->for($this->user)->fromConnector('notion')->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
    $notion->forceFill(['status' => ConnectionStatus::NeedsAuth, 'last_error' => 'The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.'])->save();
    $broken = Connection::factory()->for($this->user)->failed()->create(['name' => 'Broken', 'handle' => 'broken']);
    $fine = Connection::factory()->for($this->user)->connected()->create(['name' => 'Fine', 'handle' => 'fine']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['Notion', 'The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.', 'Needs sign-in', 'Reconnect'])
        ->assertSee(route('connections.connect', $notion))
        ->assertSeeTextInOrder(['Broken', 'The server answered with HTTP 503.', 'Error'])
        ->assertDontSee(route('connections.connect', $broken))
        ->assertDontSee(route('connections.connect', $fine));
});

it('says a sign-in that was never finished needs finishing', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('linear')->oauth()->create(['name' => 'Linear']);
    $connection->forceFill(['status' => ConnectionStatus::NeedsAuth])->save();

    Livewire::test('pages::connections.index')
        ->assertSeeText('Sign-in isn\'t finished. Reconnect to sign in and load its tools.')
        ->assertSee(route('connections.connect', $connection));
});

it('doesn\'t offer to reconnect a header sign-in the server refused, which is fixed on the Connection\'s page', function (): void {
    $connection = Connection::factory()->for($this->user)->withHeader()->create(['name' => 'Linear', 'status' => ConnectionStatus::NeedsAuth, 'last_error' => 'The server refused the token.']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['Linear', 'The server refused the token.', 'Needs sign-in'])
        ->assertDontSee(route('connections.connect', $connection));
});

it('refreshes a Connection\'s tools from its menu', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search'], ['name' => 'fetch']]);
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);

    Livewire::test('pages::connections.index')
        ->assertSeeHtml("wire:click=\"refreshTools({$connection->id})\"")
        ->call('refreshTools', $connection->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'DeepWiki: Nexus loaded 2 tools.'
            && $params['dataset']['variant'] === 'success')
        ->assertSeeTextInOrder(['DeepWiki', 'deepwiki', '2', 'Connected']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->tools()->pluck('name')->all())->toEqualCanonicalizing(['search', 'fetch']);
});

it('says the refresh left the user\'s other accounts of the service alone', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);
    Connection::factory()->for($this->user)->create(['name' => 'DeepWiki 2', 'handle' => 'deepwiki-2', 'url' => FakeMcpServer::DEFAULT_URL]);

    Livewire::test('pages::connections.index')
        ->call('refreshTools', $connection->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'DeepWiki: Nexus loaded 1 tool. Only this account was refreshed, not DeepWiki 2.');
});

it('says why a refresh from the menu failed', function (): void {
    FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::httpStatus(503));
    $connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki']);

    Livewire::test('pages::connections.index')
        ->call('refreshTools', $connection->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'DeepWiki: Nexus couldn\'t load the tools: The server answered with HTTP 503.'
            && $params['dataset']['variant'] === 'danger')
        ->assertSeeText('The server answered with HTTP 503.');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error);
});

it('doesn\'t refresh another user\'s Connection', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->create();

    Livewire::test('pages::connections.index')
        ->call('refreshTools', $connection->id)
        ->assertNotFound();

    expect($server->requests())->toBeEmpty();
});

it('counts the user\'s Connections against the limit', function (): void {
    config(['nexus.plans.free.connections' => 5]);
    Connection::factory()->for($this->user)->count(2)->create();
    Connection::factory()->create();

    Livewire::test('pages::connections.index')
        ->assertSeeText('Connections used: 2 / 5')
        ->assertSeeHtml('href="#add-more"')
        ->assertDontSeeText('Connection limit reached');
});

it('says on each catalog card how many accounts of the service the user has connected', function (): void {
    Connection::factory()->for($this->user)->fromConnector('github')->count(2)->create();
    Connection::factory()->for($this->user)->fromConnector('linear')->create();
    Connection::factory()->for($this->user)->create();
    Connection::factory()->fromConnector('notion')->create();

    $html = Livewire::test('pages::connections.index')->html();

    expect(catalogCard($html, 'github'))->toContain('2 connected', 'Add another account')
        ->and(catalogCard($html, 'linear'))->toContain('1 connected', 'Add another account')
        ->and(catalogCard($html, 'notion'))->not->toContain('connected', 'Add another account');
});

it('filters the catalog by what the user searches for', function (): void {
    Livewire::test('pages::connections.index')
        ->set('search', 'ISSUES')
        ->assertSeeHtml('data-connector="github"')
        ->assertSeeHtml('data-connector="linear"')
        ->assertDontSeeHtml('data-connector="notion"')
        ->assertSeeText('Custom server')
        ->set('search', 'notion')
        ->assertSeeHtml('data-connector="notion"')
        ->assertDontSeeHtml('data-connector="github"');
});

it('offers a custom server when nothing in the catalog matches', function (): void {
    Livewire::test('pages::connections.index')
        ->set('search', 'sentry')
        ->assertSeeText('No server in the catalog matches “sentry”')
        ->assertSee(route('connections.add-custom'))
        ->assertDontSeeHtml('data-connector="github"')
        ->set('search', '')
        ->assertSeeHtml('data-connector="github"');
});

it('escapes the names users give their Connections and Stars, and what they search for', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['name' => '<script>alert("name")</script>']);
    Star::factory()->for($this->user)->including($connection)->create(['name' => '<script>alert("star")</script>']);

    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;name&quot;)', escape: false)
        ->assertSee('&lt;script&gt;alert(&quot;star&quot;)', escape: false)
        ->assertDontSee('<script>alert("name")</script>', escape: false)
        ->assertDontSee('<script>alert("star")</script>', escape: false);

    Livewire::test('pages::connections.index')
        ->set('search', '<img src=x onerror=alert(1)>')
        ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;')
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>');
});
