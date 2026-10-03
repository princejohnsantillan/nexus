<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
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

/**
 * The header of a Star page: the breadcrumb, the name, the access mode
 * badge, the URL in the endpoint pill and what its Copy button copies, and
 * the tabs, each with whether it is the current one.
 *
 * @return array{breadcrumb: list<array{text: string, href: string|null, current: bool}>, name: string, accessMode: string, url: string, copiesTheUrl: bool, tabs: array<string, array{href: string, current: bool}>}
 */
function starHeader(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $squish = fn (?string $text): string => trim((string) preg_replace('/\s+/', ' ', (string) $text));
    $endpoint = $document->querySelector('[data-star-endpoint]');
    $tabs = [];

    foreach ($document->querySelectorAll('[data-flux-navbar] a') as $tab) {
        $tabs[$squish($tab->textContent)] = ['href' => (string) $tab->getAttribute('href'), 'current' => $tab->getAttribute('aria-current') === 'page'];
    }

    return [
        'breadcrumb' => array_map(fn (Element $item): array => [
            'text' => $squish($item->textContent),
            'href' => $item->querySelector('a')?->getAttribute('href'),
            'current' => $item->querySelector('[aria-current="page"]') instanceof Element,
        ], iterator_to_array($document->querySelectorAll('nav[aria-label="Breadcrumb"] [data-flux-breadcrumbs-item]'))),
        'name' => $squish($document->querySelector('h1')?->textContent),
        'accessMode' => $squish($document->querySelector('[data-star-access-mode]')?->textContent),
        'url' => (string) $endpoint?->querySelector('[data-star-endpoint-url]')?->textContent,
        'copiesTheUrl' => str_contains((string) $endpoint?->querySelector('[data-star-endpoint-copy]')?->getAttribute('x-on:click'), 'navigator.clipboard.writeText($refs.url.textContent)'),
        'tabs' => $tabs,
    ];
}

it('heads every Star page with its breadcrumb, name, access mode, description, URL and tabs', function (string $route, string $tab): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work', 'description' => 'For the work laptop']);

    $response = $this->get(route($route, $star))->assertSeeText('For the work laptop');

    expect(starHeader((string) $response->getContent()))->toBe([
        'breadcrumb' => [
            ['text' => 'Stars', 'href' => route('stars.index'), 'current' => false],
            ['text' => 'Work', 'href' => null, 'current' => true],
        ],
        'name' => 'Work',
        'accessMode' => 'Bearer token',
        'url' => url('/mcp/'.$star->public_id),
        'copiesTheUrl' => true,
        'tabs' => [
            'Overview' => ['href' => route('stars.show', $star), 'current' => $tab === 'Overview'],
            'Tools' => ['href' => route('stars.tools', $star), 'current' => $tab === 'Tools'],
            'Prompts' => ['href' => route('stars.prompts', $star), 'current' => $tab === 'Prompts'],
            'Access' => ['href' => route('stars.access', $star), 'current' => $tab === 'Access'],
        ],
    ]);
})->with([
    'overview' => ['stars.show', 'Overview'],
    'tools' => ['stars.tools', 'Tools'],
    'prompts' => ['stars.prompts', 'Prompts'],
    'access' => ['stars.access', 'Access'],
]);

it('shows the endpoint URL and the access mode in the header', function (StarAccessMode $mode, string $label): void {
    $star = Star::factory()->for($this->user)->withAccessMode($mode)->create();

    $header = starHeader((string) $this->get(route('stars.show', $star))->getContent());

    expect([$header['accessMode'], $header['url']])->toBe([$label, url('/mcp/'.$star->public_id)]);
})->with([
    'bearer token' => [StarAccessMode::Token, 'Bearer token'],
    'OAuth' => [StarAccessMode::OAuth, 'OAuth'],
]);

it('shows the signed URL in the header in signed-URL mode', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();

    $header = starHeader((string) $this->get(route('stars.show', $star))->getContent());

    expect($header['accessMode'])->toBe('Signed URL')
        ->and($header['url'])->toStartWith(url('/mcp/'.$star->public_id).'?v=1&signature=');
});

it('shows the Star\'s Connections and settings in cards', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => true]);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => false]);
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);

    $this->get(route('stars.show', $star))
        ->assertSeeTextInOrder([
            'Set up a client',
            'Connections', 'DeepWiki', 'deepwiki', '2 tools', 'Taking one out forgets the switches you set for its tools here.', 'Save connections',
            'Details', 'Renaming keeps the endpoint URL, so clients keep working.', 'Save details',
            'Delete this Star', 'This can\'t be undone.', 'Delete Star',
        ]);
});

/**
 * Each stat tile by its label: its value, the text beside it, the tone the
 * value is coloured in and whether it draws a sparkline.
 *
 * @return array<string, array{value: string, secondary: string|null, tone: string|null, sparkline: bool}>
 */
function starStatTiles(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $tiles = [];

    foreach ($document->querySelectorAll('[data-stat-tile]') as $tile) {
        [$label, $secondary] = array_pad(array_map(fn (Element $text): string => trim((string) $text->textContent), iterator_to_array($tile->querySelectorAll('[data-flux-text]'))), 2, null);
        $value = $tile->querySelector('span');

        $tiles[(string) $label] = [
            'value' => trim((string) $value?->textContent),
            'secondary' => $secondary,
            'tone' => collect(['success', 'warning', 'danger'])->first(fn (string $tone): bool => $value?->classList->contains('text-'.$tone) ?? false),
            'sparkline' => $tile->querySelector('[data-sparkline]') instanceof Element,
        ];
    }

    return $tiles;
}

it('shows how the Star is doing: its tools on and its last 24 hours of calls', function (): void {
    $this->travelTo('2026-10-03 12:00:00');
    $wiki = Connection::factory()->for($this->user)->create();
    ConnectionTool::factory()->for($wiki)->create(['read_only' => true]);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => false]);
    $star = Star::factory()->for($this->user)->including($wiki)->create();
    ActivityEntry::factory()->through($star, $wiki)->create(['created_at' => '2026-10-02 09:00:00']);
    ActivityEntry::factory()->through($star, $wiki)->create(['created_at' => '2026-10-03 08:00:00']);
    ActivityEntry::factory()->through($star, $wiki)->withStatus(ActivityStatus::Timeout)->create(['created_at' => '2026-10-03 09:00:00']);
    ActivityEntry::factory()->through($star, $wiki)->create(['created_at' => '2026-10-03 11:55:00', 'client_name' => 'Claude Code']);

    $html = (string) $this->get(route('stars.show', $star))->getContent();

    expect(starStatTiles($html))->toBe([
        'Tools on' => ['value' => '1', 'secondary' => 'of 2', 'tone' => null, 'sparkline' => false],
        'Calls · 24h' => ['value' => '3', 'secondary' => null, 'tone' => null, 'sparkline' => true],
        'Errors · 24h' => ['value' => '1', 'secondary' => '33.3%', 'tone' => 'danger', 'sparkline' => false],
        'Last call' => ['value' => '5m ago', 'secondary' => 'Claude Code', 'tone' => null, 'sparkline' => false],
    ]);
});

it('names how a client without a name called', function (): void {
    $this->travelTo('2026-10-03 12:00:00');
    $wiki = Connection::factory()->for($this->user)->create();
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->including($wiki)->create();
    ActivityEntry::factory()->through($star, $wiki)->create(['created_at' => '2026-09-30 12:00:00', 'via' => StarAccessMode::SignedUrl, 'client_name' => null]);

    $tiles = starStatTiles((string) $this->get(route('stars.show', $star))->getContent());

    expect($tiles['Last call'])->toBe(['value' => '3d ago', 'secondary' => 'Signed URL', 'tone' => null, 'sparkline' => false]);
});

it('says when the Star has had no calls', function (): void {
    $star = Star::factory()->for($this->user)->create();

    $tiles = starStatTiles((string) $this->get(route('stars.show', $star))->getContent());

    expect(array_slice($tiles, 1))->toBe([
        'Calls · 24h' => ['value' => '0', 'secondary' => null, 'tone' => null, 'sparkline' => false],
        'Errors · 24h' => ['value' => '0', 'secondary' => null, 'tone' => null, 'sparkline' => false],
        'Last call' => ['value' => 'No calls yet', 'secondary' => null, 'tone' => null, 'sparkline' => false],
    ]);
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
        ->assertSeeText('Each client below takes only the URL, then signs in to Nexus')
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

/**
 * Each code panel on the page: the title in its header, the text its Copy
 * button copies, and whether that button copies the panel's own code.
 *
 * @return list<array{title: string, code: string, copiesItsCode: bool}>
 */
function starCodePanels(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $panels = [];

    foreach ($document->querySelectorAll('[data-code-panel]') as $panel) {
        $panels[] = [
            'title' => trim((string) $panel->querySelector('[data-code-panel-title]')?->textContent),
            'code' => (string) $panel->querySelector('pre[x-ref="code"]')?->textContent,
            'copiesItsCode' => str_contains((string) $panel->querySelector('[data-code-panel-copy]')?->getAttribute('x-on:click'), 'navigator.clipboard.writeText($refs.code.textContent)'),
        ];
    }

    return $panels;
}

it('shows each setup snippet in a code panel that names its file or the terminal and copies exactly the snippet', function (): void {
    $star = Star::factory()->for($this->user)->create(['slug' => 'work']);
    $url = url('/mcp/'.$star->public_id);
    $toml = "[mcp_servers.nexus-work]\nurl = \"{$url}\"\nbearer_token_env_var = \"NEXUS_WORK_TOKEN\"";
    $cursor = <<<JSON
        {
            "mcpServers": {
                "nexus-work": {
                    "url": "{$url}",
                    "headers": {
                        "Authorization": "Bearer \${env:NEXUS_WORK_TOKEN}"
                    }
                }
            }
        }
        JSON;

    $html = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(starCodePanels($html))->toBe([
        ['title' => 'terminal', 'code' => 'export NEXUS_WORK_TOKEN=nxs_…', 'copiesItsCode' => true],
        ['title' => 'terminal', 'code' => "claude mcp add-json --scope user nexus-work '{\"type\":\"http\",\"url\":\"{$url}\",\"headers\":{\"Authorization\":\"Bearer \${NEXUS_WORK_TOKEN}\"}}'", 'copiesItsCode' => true],
        ['title' => '~/.codex/config.toml', 'code' => $toml, 'copiesItsCode' => true],
        ['title' => '~/.cursor/mcp.json', 'code' => $cursor, 'copiesItsCode' => true],
        ['title' => '~/.grok/config.toml', 'code' => $toml, 'copiesItsCode' => true],
    ]);
});

it('heads the URL claude.ai takes as a URL and each login command as the terminal', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['slug' => 'work']);
    $url = url('/mcp/'.$star->public_id);

    $html = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(array_map(fn (array $panel): array => [$panel['title'], $panel['code']], starCodePanels($html)))->toBe([
        ['URL', $url],
        ['terminal', "claude mcp add --transport http --scope user nexus-work '{$url}'"],
        ['terminal', 'claude mcp login nexus-work'],
        ['~/.codex/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\""],
        ['terminal', 'codex mcp login nexus-work'],
        ['~/.cursor/mcp.json', "{\n    \"mcpServers\": {\n        \"nexus-work\": {\n            \"url\": \"{$url}\"\n        }\n    }\n}"],
        ['terminal', 'cursor-agent mcp login nexus-work'],
        ['~/.grok/config.toml', "[mcp_servers.nexus-work]\nurl = \"{$url}\""],
    ]);
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

it('sends guests to sign in', function (string $route): void {
    $star = Star::factory()->for($this->user)->create();
    auth()->logout();

    $this->get(route($route, $star))->assertRedirect(route('auth.sign-in'));
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
