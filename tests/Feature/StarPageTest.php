<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use App\Models\User;
use Livewire\Livewire;

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
        ->assertSeeTextInOrder(['Stars', 'Work', 'For the work laptop', 'Overview', 'Tools'])
        ->assertSee(route('stars.tools', $star))
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

it('lives at its public id, never its numeric id', function (): void {
    $star = Star::factory()->for($this->user)->create();

    expect(route('stars.show', $star))->toBe(url('/stars/'.$star->public_id));

    $this->get('/stars/'.$star->id)->assertNotFound();
});

it('does not find another user\'s Star', function (string $route): void {
    $star = Star::factory()->create();

    $this->get(route($route, $star))->assertNotFound();
})->with(['stars.show', 'stars.tools', 'stars.access']);

it('sends guests to the welcome page', function (string $route): void {
    $star = Star::factory()->for($this->user)->create();
    auth()->logout();

    $this->get(route($route, $star))->assertRedirect(route('home'));
})->with(['stars.show', 'stars.tools', 'stars.access']);

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

it('escapes the Star\'s name and description', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => '<script>alert("name")</script>', 'description' => '<img src=x onerror=alert(1)>']);

    $this->get(route('stars.show', $star))
        ->assertOk()
        ->assertDontSee('<script>alert("name")</script>', escape: false)
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
});
