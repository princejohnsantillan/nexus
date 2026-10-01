<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\StarAccessMode;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use App\Models\User;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Tests\Support\StarClient;
use Tests\Support\StarOAuthFlow;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

/**
 * Match the Flux toast with this text and variant.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function starToast(string $text, string $variant = 'success'): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text && $params['dataset']['variant'] === $variant;
}

it('shows the Star\'s endpoint, access mode, tools and sub-pages', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => true]);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => false]);
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work', 'description' => 'For the work laptop']);

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertSee('<title>Star · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Stars', 'Work', 'For the work laptop', 'Overview', 'Tools', 'Prompts', 'Access'])
        ->assertSee(route('stars.tools', $star))
        ->assertSee(route('stars.prompts', $star))
        ->assertSee('value="'.url('/mcp/'.$star->public_id).'"', escape: false)
        ->assertSeeTextInOrder(['Access', 'Bearer token', 'Tools on', '1 of 2'])
        ->assertSeeTextInOrder(['Connections', 'DeepWiki', 'deepwiki', '2 tools']);
});

it('shows setup for each client that reads the token from the environment', function (): void {
    $star = Star::factory()->for($this->user)->create(['slug' => 'work-2']);
    $url = url('/mcp/'.$star->public_id);

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertSee(route('stars.access', $star))
        ->assertSeeText('export NEXUS_WORK_2_TOKEN=nxs_…')
        ->assertSeeTextInOrder(['Claude Code', 'Codex', '~/.codex/config.toml', 'Cursor', '~/.cursor/mcp.json', 'Grok', '~/.grok/config.toml'])
        ->assertSeeText("claude mcp add-json --scope user nexus-work-2 '{\"type\":\"http\",\"url\":\"{$url}\",\"headers\":{\"Authorization\":\"Bearer \${NEXUS_WORK_2_TOKEN}\"}}'")
        ->assertSeeText("[mcp_servers.nexus-work-2]\nurl = \"{$url}\"\nbearer_token_env_var = \"NEXUS_WORK_2_TOKEN\"")
        ->assertSeeText('"Authorization": "Bearer ${env:NEXUS_WORK_2_TOKEN}"');
});

it('shows setup for each client with the signed URL alone in signed-URL mode', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create(['slug' => 'work']);
    $url = $star->signedUrl();

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertSeeTextInOrder(['Access', 'Signed URL'])
        ->assertSee('value="'.e($url).'"', escape: false)
        ->assertSee(route('stars.access', $star))
        ->assertSeeTextInOrder([
            'claude.ai', 'Open Settings → Connectors, choose "Add custom connector" and paste this URL:', $url,
            'Claude Code', "claude mcp add --transport http --scope user nexus-work '{$url}'",
            'Codex', '~/.codex/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\"",
            'Cursor', '~/.cursor/mcp.json', '"nexus-work": {', '"url": "'.$url.'"',
            'Grok', '~/.grok/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\"",
        ])
        ->assertDontSeeText('NEXUS_WORK_TOKEN')
        ->assertDontSeeText('Authorization');
});

it('shows setup for each client with the URL alone and its login step in OAuth mode', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['slug' => 'work']);
    $url = url('/mcp/'.$star->public_id);

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertSeeTextInOrder(['Access', 'OAuth'])
        ->assertSee('value="'.$url.'"', escape: false)
        ->assertSeeText('When it connects, it sends you to Nexus to sign in and approve it for this Star.')
        ->assertSeeTextInOrder([
            'claude.ai', 'Open Settings → Connectors, choose "Add custom connector" and paste this URL:', $url,
            'Then choose Connect next to it. claude.ai sends you to Nexus to approve it.',
            'Claude Code', "claude mcp add --transport http --scope user nexus-work '{$url}'",
            'Then sign in from a terminal, or run /mcp in Claude Code, choose nexus-work and Authenticate. Approve it in Nexus when your browser opens.', 'claude mcp login nexus-work',
            'Codex', '~/.codex/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\"",
            'Then sign in from a terminal. Approve it in Nexus when your browser opens.', 'codex mcp login nexus-work',
            'Cursor', '~/.cursor/mcp.json', '"url": "'.$url.'"',
            'Then sign in when Cursor\'s MCP settings say nexus-work needs it, or with the Cursor CLI.', 'cursor-agent mcp login nexus-work',
            'Grok', '~/.grok/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\"",
            'Then open /mcps in Grok, choose nexus-work and press i to sign in. Approve it in Nexus when your browser opens.',
        ])
        ->assertDontSeeText('NEXUS_WORK_TOKEN')
        ->assertDontSeeText('signature=')
        ->assertDontSeeText('Authorization');
});

it('lives at its public id, never its numeric id', function (): void {
    $star = Star::factory()->for($this->user)->create();

    expect(route('stars.show', $star))->toBe(url('/stars/'.$star->public_id));

    $this->get('/stars/'.$star->id)->assertNotFound();
});

it('does not find another user\'s Star', function (string $route): void {
    $star = Star::factory()->create();

    $this->get(route($route, $star))->assertNotFound();
})->with(['stars.show', 'stars.tools', 'stars.prompts', 'stars.access']);

it('sends guests to the welcome page', function (string $route): void {
    $star = Star::factory()->for($this->user)->create();
    auth()->logout();

    $this->get(route($route, $star))->assertRedirect(route('home'));
})->with(['stars.show', 'stars.tools', 'stars.prompts', 'stars.access']);

it('changes which Connections the Star includes', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);
    $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs']);
    $star = Star::factory()->for($this->user)->including($wiki)->create();

    Livewire::test('pages::stars.show', ['star' => $star])
        ->assertSet('connectionIds', [(string) $wiki->id])
        ->set('connectionIds', [(string) $docs->id])
        ->call('saveConnections')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', starToast('Saved. The Star includes 1 Connection.'));

    expect($star->connections()->pluck('name')->all())->toBe(['Docs']);
});

it('forgets the switches of a Connection taken out of the Star', function (): void {
    $wiki = Connection::factory()->for($this->user)->create();
    $docs = Connection::factory()->for($this->user)->create();
    ConnectionTool::factory()->for($wiki)->create(['name' => 'write_page']);
    ConnectionTool::factory()->for($docs)->create(['name' => 'write_doc']);
    $star = Star::factory()->for($this->user)->including($wiki, $docs)->create();
    resolve(SwitchStarTools::class)->handle($star, $wiki, true);
    resolve(SwitchStarTools::class)->handle($star, $docs, true);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->set('connectionIds', [(string) $docs->id])
        ->call('saveConnections');

    expect($star->toolSwitches()->pluck('tool_name')->all())->toBe(['write_doc']);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->set('connectionIds', [(string) $wiki->id, (string) $docs->id])
        ->call('saveConnections');

    expect($star->toolSwitches()->pluck('tool_name')->all())->toBe(['write_doc']);
});

it('refuses another user\'s Connection', function (): void {
    $star = Star::factory()->for($this->user)->create();
    $someoneElses = Connection::factory()->create();

    Livewire::test('pages::stars.show', ['star' => $star])
        ->set('connectionIds', [(string) $someoneElses->id])
        ->call('saveConnections')
        ->assertHasErrors(['connectionIds.0' => 'Choose only your own Connections.']);

    expect($star->connections()->count())->toBe(0);
});

it('says when the user has no Connections to add', function (): void {
    $star = Star::factory()->for($this->user)->create();

    Livewire::test('pages::stars.show', ['star' => $star])
        ->assertSeeText('No Connections yet')
        ->assertSee(route('connections.add'));
});

it('renames the Star and edits its description, keeping its URL', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work', 'slug' => 'work', 'description' => null]);
    $url = route('stars.show', $star);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->set('name', ' Office ')
        ->set('description', 'For the office laptop')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', starToast('Saved.'))
        ->assertSeeText('Office');

    expect($star->refresh()->only(['name', 'slug', 'description']))->toBe(['name' => 'Office', 'slug' => 'work', 'description' => 'For the office laptop'])
        ->and(route('stars.show', $star))->toBe($url);
});

it('requires a name', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->set('name', '')
        ->call('saveDetails')
        ->assertHasErrors(['name' => 'required']);

    expect($star->refresh()->name)->toBe('Work');
});

it('deletes the Star and its switches after confirming, keeping the Connections', function (): void {
    $wiki = Connection::factory()->for($this->user)->create();
    ConnectionTool::factory()->for($wiki)->create();
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);
    resolve(SwitchStarTools::class)->handle($star, $wiki, true);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->assertSeeText('Delete Work?')
        ->call('delete')
        ->assertRedirect(route('stars.index'));

    $this->assertModelMissing($star);
    $this->assertModelExists($wiki);
    expect(StarToolSwitch::query()->count())->toBe(0);
    $this->get(route('stars.index'))->assertSeeText('Deleted Work.');
});

it('revokes the OAuth clients that registered with a Star when it is deleted', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
    $other = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
    $clientId = StarOAuthFlow::register($star);
    $tokens = StarOAuthFlow::signIn($this->user, $clientId);
    $otherClientId = StarOAuthFlow::register($other);

    Livewire::test('pages::stars.show', ['star' => $star])
        ->assertSeeText('its connected apps are revoked')
        ->call('delete')
        ->assertRedirect(route('stars.index'));

    $this->assertModelMissing($star);
    expect(Client::query()->findOrFail($clientId)->revoked)->toBeTrue()
        ->and(Client::query()->findOrFail($otherClientId)->revoked)->toBeFalse();
    StarOAuthFlow::refresh($clientId, $tokens['refresh_token'])->assertUnauthorized();
    StarClient::for($star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
});

it('escapes the Star\'s name and description', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => '<script>alert("name")</script>', 'description' => '<img src=x onerror=alert(1)>']);

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertDontSee('<script>alert("name")</script>', escape: false)
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
});
