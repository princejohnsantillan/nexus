<?php

declare(strict_types=1);

use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Cookie;
use Laravel\Passport\Client;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

/**
 * The parsed "Set up a client" card of a Star's overview.
 */
function setupCard(string $html): Element
{
    $card = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('#setup');

    expect($card)->toBeInstanceOf(Element::class);

    return $card;
}

/**
 * Text with its runs of whitespace squished to single spaces.
 */
function squished(?string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
}

/**
 * The clients in the picker by value, in order, each with its label and
 * whether it has the dot of a client that has reached the Star.
 *
 * @return array<string, array{label: string, called: bool}>
 */
function setupPicker(string $html): array
{
    $clients = [];

    foreach (setupCard($html)->querySelectorAll('[data-setup-client]') as $client) {
        $called = $client->querySelector('[data-setup-client-called]') instanceof Element;

        $clients[(string) $client->getAttribute('data-setup-client')] = [
            'label' => squished($called ? $client->querySelector('[data-setup-client-called] + span')?->textContent : $client->textContent),
            'called' => $called,
        ];
    }

    return $clients;
}

/**
 * The numbered steps, in order: each one's number, heading, text and code
 * panels (the title in a panel's header and the code its Copy button
 * copies, which must be the panel's own code).
 *
 * @return list<array{number: string, heading: string, text: list<string>, panels: list<array{title: string, code: string}>}>
 */
function setupSteps(string $html): array
{
    $steps = [];

    foreach (setupCard($html)->querySelectorAll('[data-setup-steps] > [data-step]') as $step) {
        $panels = [];

        foreach ($step->querySelectorAll('[data-code-panel]') as $panel) {
            expect((string) $panel->querySelector('[data-code-panel-copy]')?->getAttribute('x-on:click'))->toContain('navigator.clipboard.writeText($refs.code.textContent)');

            $panels[] = [
                'title' => squished($panel->querySelector('[data-code-panel-title]')?->textContent),
                'code' => (string) $panel->querySelector('pre[x-ref="code"]')?->textContent,
            ];
        }

        $steps[] = [
            'number' => squished($step->querySelector('[data-step-number]')?->textContent),
            'heading' => squished($step->querySelector('[data-step-heading]')?->textContent),
            'text' => array_values(array_map(
                fn (Element $text): string => squished($text->textContent),
                array_filter(iterator_to_array($step->querySelectorAll('[data-flux-text]')), fn (Element $text): bool => ! $text->closest('[data-setup-check]') instanceof Element),
            )),
            'panels' => $panels,
        ];
    }

    return $steps;
}

/**
 * The text "Copy setup as prompt" copies, after checking the button copies it.
 */
function setupPrompt(string $html): string
{
    $card = setupCard($html);

    expect((string) $card->querySelector('[data-setup-prompt-copy]')?->getAttribute('x-on:click'))->toContain('navigator.clipboard.writeText($refs.prompt.textContent)');

    return (string) $card->querySelector('pre[x-ref="prompt"][data-setup-prompt]')?->textContent;
}

/**
 * "Check it works": its state (listening, heard or stopped), whether it
 * polls, and its text.
 *
 * @return array{state: string, polls: bool, text: string}
 */
function setupCheck(string $html): array
{
    $check = setupCard($html)->querySelector('[data-setup-check]');

    return [
        'state' => (string) $check?->getAttribute('data-setup-check'),
        'polls' => $check?->hasAttribute('wire:poll.5s') ?? false,
        'text' => squished($check?->textContent),
    ];
}

it('offers the clients that can reach the Star in its access mode', function (StarAccessMode $mode, array $clients): void {
    $star = Star::factory()->for($this->user)->withAccessMode($mode)->create();

    $picker = setupPicker((string) $this->get(route('stars.show', $star))->getContent());

    expect(array_map(fn (array $client): string => $client['label'], $picker))->toBe($clients);
})->with([
    'bearer token' => [StarAccessMode::Token, ['claude-code' => 'Claude Code', 'codex' => 'Codex', 'cursor' => 'Cursor', 'grok' => 'Grok']],
    'signed URL' => [StarAccessMode::SignedUrl, ['claude-ai' => 'claude.ai', 'claude-code' => 'Claude Code', 'codex' => 'Codex', 'cursor' => 'Cursor', 'grok' => 'Grok']],
    'OAuth' => [StarAccessMode::OAuth, ['claude-ai' => 'claude.ai', 'claude-code' => 'Claude Code', 'codex' => 'Codex', 'cursor' => 'Cursor', 'grok' => 'Grok']],
]);

it('shows only the chosen client\'s steps, reading the token from the environment in token mode', function (string $client, string $label, string $title, string $code): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work', 'slug' => 'work-2']);
    $url = url('/mcp/'.$star->public_id);

    $html = Livewire::test('pages::stars.show', ['star' => $star])->set('client', $client)->html();

    expect(setupSteps($html))->toBe([
        ['number' => '1', 'heading' => 'Put a token in your shell', 'text' => [], 'panels' => [['title' => '~/.zshrc', 'code' => 'export NEXUS_WORK_2_TOKEN=nxs_…']]],
        ['number' => '2', 'heading' => 'Add Work to '.$label, 'text' => [], 'panels' => [['title' => $title, 'code' => str_replace('{url}', $url, $code)]]],
        ['number' => '3', 'heading' => 'Check it works', 'text' => [], 'panels' => []],
    ]);
})->with([
    'Claude Code' => ['claude-code', 'Claude Code', 'terminal', "claude mcp add-json --scope user nexus-work-2 '{\"type\":\"http\",\"url\":\"{url}\",\"headers\":{\"Authorization\":\"Bearer \${NEXUS_WORK_2_TOKEN}\"}}'"],
    'Codex' => ['codex', 'Codex', '~/.codex/config.toml', "[mcp_servers.nexus-work-2]\nurl = \"{url}\"\nbearer_token_env_var = \"NEXUS_WORK_2_TOKEN\""],
    'Cursor' => ['cursor', 'Cursor', '~/.cursor/mcp.json', <<<'JSON'
        {
            "mcpServers": {
                "nexus-work-2": {
                    "url": "{url}",
                    "headers": {
                        "Authorization": "Bearer ${env:NEXUS_WORK_2_TOKEN}"
                    }
                }
            }
        }
        JSON],
    'Grok' => ['grok', 'Grok', '~/.grok/config.toml', "[mcp_servers.nexus-work-2]\nurl = \"{url}\"\nbearer_token_env_var = \"NEXUS_WORK_2_TOKEN\""],
]);

it('shows only the chosen client\'s steps with the signed URL alone in signed-URL mode', function (string $client, string $label, array $text, string $title, string $code): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create(['name' => 'Work', 'slug' => 'work']);

    $html = Livewire::test('pages::stars.show', ['star' => $star])->set('client', $client)->html();

    expect(setupSteps($html))->toBe([
        ['number' => '1', 'heading' => 'Add Work to '.$label, 'text' => $text, 'panels' => [['title' => $title, 'code' => str_replace('{url}', $star->signedUrl(), $code)]]],
        ['number' => '2', 'heading' => 'Check it works', 'text' => [], 'panels' => []],
    ])->and($html)->not->toContain('NEXUS_WORK_TOKEN')->not->toContain('Authorization');
})->with([
    'claude.ai' => ['claude-ai', 'claude.ai', ['Open Settings → Connectors, choose "Add custom connector" and paste this URL:'], 'URL', '{url}'],
    'Claude Code' => ['claude-code', 'Claude Code', [], 'terminal', "claude mcp add --transport http --scope user nexus-work '{url}'"],
    'Codex' => ['codex', 'Codex', [], '~/.codex/config.toml', "[mcp_servers.nexus-work]\nurl = \"{url}\""],
    'Cursor' => ['cursor', 'Cursor', [], '~/.cursor/mcp.json', "{\n    \"mcpServers\": {\n        \"nexus-work\": {\n            \"url\": \"{url}\"\n        }\n    }\n}"],
    'Grok' => ['grok', 'Grok', [], '~/.grok/config.toml', "[mcp_servers.nexus-work]\nurl = \"{url}\""],
]);

it('shows only the chosen client\'s steps with the URL alone and its sign-in in OAuth mode', function (string $client, string $label, array $add, string $login, ?string $loginCommand): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Work', 'slug' => 'work']);
    $url = url('/mcp/'.$star->public_id);

    $html = Livewire::test('pages::stars.show', ['star' => $star])->set('client', $client)->html();

    expect(setupSteps($html))->toBe([
        ['number' => '1', 'heading' => 'Add Work to '.$label, 'text' => $add['text'] ?? [], 'panels' => [['title' => $add['title'], 'code' => str_replace('{url}', $url, $add['code'])]]],
        ['number' => '2', 'heading' => 'Sign in and approve it', 'text' => [$login], 'panels' => $loginCommand === null ? [] : [['title' => 'terminal', 'code' => $loginCommand]]],
        ['number' => '3', 'heading' => 'Check it works', 'text' => [], 'panels' => []],
    ])->and($html)->not->toContain('signature=')->not->toContain('NEXUS_WORK_TOKEN');
})->with([
    'claude.ai' => ['claude-ai', 'claude.ai', ['text' => ['Open Settings → Connectors, choose "Add custom connector" and paste this URL:'], 'title' => 'URL', 'code' => '{url}'], 'Then choose Connect next to it. claude.ai sends you to Nexus to approve it.', null],
    'Claude Code' => ['claude-code', 'Claude Code', ['title' => 'terminal', 'code' => "claude mcp add --transport http --scope user nexus-work '{url}'"], 'Then sign in from a terminal, or run /mcp in Claude Code, choose nexus-work and Authenticate. Approve it in Nexus when your browser opens.', 'claude mcp login nexus-work'],
    'Codex' => ['codex', 'Codex', ['title' => '~/.codex/config.toml', 'code' => "[mcp_servers.nexus-work]\nurl = \"{url}\""], 'Then sign in from a terminal. Approve it in Nexus when your browser opens.', 'codex mcp login nexus-work'],
    'Cursor' => ['cursor', 'Cursor', ['title' => '~/.cursor/mcp.json', 'code' => "{\n    \"mcpServers\": {\n        \"nexus-work\": {\n            \"url\": \"{url}\"\n        }\n    }\n}"], 'Then sign in when Cursor\'s MCP settings say nexus-work needs it, or with the Cursor CLI. Approve it in Nexus when your browser opens.', 'cursor-agent mcp login nexus-work'],
    'Grok' => ['grok', 'Grok', ['title' => '~/.grok/config.toml', 'code' => "[mcp_servers.nexus-work]\nurl = \"{url}\""], 'Then open /mcps in Grok, choose nexus-work and press i to sign in. Approve it in Nexus when your browser opens.', null],
]);

it('links the token step to creating a token for the chosen client on the Access page', function (): void {
    $star = Star::factory()->for($this->user)->create();

    $link = setupCard(Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'cursor')->html())->querySelector('[data-setup-create-token]');

    expect([squished($link?->textContent), $link?->getAttribute('href')])->toBe(['Create a token for Cursor →', route('stars.access', ['star' => $star, 'new_token' => 'Cursor'])]);
});

it('remembers the chosen client in the browser', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);

    Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'codex');

    expect(Cookie::queued('nexus_setup_client')?->getValue())->toBe('codex');

    $steps = setupSteps((string) $this->withCookie('nexus_setup_client', 'codex')->get(route('stars.show', $star))->getContent());

    expect($steps[1]['heading'])->toBe('Add Work to Codex');
});

it('starts with the first client when the remembered one can\'t reach the Star', function (StarAccessMode $mode, string $remembered, string $first): void {
    $star = Star::factory()->for($this->user)->withAccessMode($mode)->create(['name' => 'Work']);

    $html = (string) $this->withCookie('nexus_setup_client', $remembered)->get(route('stars.show', $star))->getContent();

    expect(setupSteps($html)[$mode === StarAccessMode::Token ? 1 : 0]['heading'])->toBe('Add Work to '.$first);
})->with([
    'claude.ai in token mode' => [StarAccessMode::Token, 'claude-ai', 'Claude Code'],
    'an unknown client' => [StarAccessMode::SignedUrl, 'netscape', 'claude.ai'],
]);

it('marks the clients that have already reached the Star in token mode', function (): void {
    $star = Star::factory()->for($this->user)->create();
    $other = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $star->id, 'client_name' => 'Claude Code (laptop)']);
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $star->id, 'client_name' => 'Laptop']);
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $other->id, 'client_name' => 'Grok']);
    StarToken::factory()->for($star)->create(['name' => 'cursor-desktop', 'last_used_at' => now()]);
    StarToken::factory()->for($star)->create(['name' => 'Codex']);

    $picker = setupPicker((string) $this->get(route('stars.show', $star))->getContent());

    expect(array_keys(array_filter($picker, fn (array $client): bool => $client['called'])))->toBe(['claude-code', 'cursor']);
});

it('marks the clients that have already reached the Star in OAuth mode, as its connected apps', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
    StarOAuthClient::factory()->for($star)->approved()->create();
    StarOAuthClient::factory()->for($star)->approved()->create(['client_id' => Client::factory()->asPublic()->createOne(['name' => 'Grok', 'revoked' => true])->id]);
    StarOAuthClient::factory()->for($star)->create(['client_id' => Client::factory()->asPublic()->createOne(['name' => 'Codex'])->id]);
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $star->id, 'via' => StarAccessMode::OAuth, 'client_name' => 'Cursor']);

    $picker = setupPicker((string) $this->get(route('stars.show', $star))->getContent());

    expect(array_keys(array_filter($picker, fn (array $client): bool => $client['called'])))->toBe(['claude-ai', 'cursor']);
});

it('copies the chosen client\'s setup as a prompt, with the endpoint and without the token', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work', 'slug' => 'work']);
    StarToken::factory()->for($star)->plain($token = StarToken::generate())->create(['name' => 'Cursor']);
    $url = url('/mcp/'.$star->public_id);
    $access = route('stars.access', $star);

    $prompt = setupPrompt(Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'cursor')->html());

    expect($prompt)->toBe(<<<PROMPT
        Add the Nexus Star "Work" to Cursor as the remote MCP server nexus-work.

        Endpoint: {$url}

        1. The Star takes a bearer token, which Cursor reads from the environment variable NEXUS_WORK_TOKEN. Never write a token into a config file. If NEXUS_WORK_TOKEN isn't set where Cursor runs, ask me to create a token for Cursor on the Star's Access page in Nexus ({$access}) and to put it in my shell profile (such as ~/.zshrc):

        ```
        export NEXUS_WORK_TOKEN=nxs_…
        ```

        2. Add this to ~/.cursor/mcp.json, keeping anything already in it:

        ```
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
        ```

        3. Restart Cursor if it is running, and check that nexus-work lists its tools.
        PROMPT)->not->toContain($token)->not->toContain(substr($token, 0, 12));
});

it('puts each client\'s steps in its prompt, holding no secret beyond the snippet', function (StarAccessMode $mode, string $client, string $command): void {
    $star = Star::factory()->for($this->user)->withAccessMode($mode)->create(['name' => 'Work', 'slug' => 'work']);

    $prompt = setupPrompt(Livewire::test('pages::stars.show', ['star' => $star])->set('client', $client)->html());

    expect($prompt)->toContain('Endpoint: '.url('/mcp/'.$star->public_id)."\n")
        ->toContain(str_replace('{url}', $star->clientUrl(), $command))
        ->and(substr_count($prompt, 'signature='))->toBe($mode === StarAccessMode::SignedUrl ? 1 : 0);
})->with([
    'Claude Code with a token' => [StarAccessMode::Token, 'claude-code', "Run this in a terminal:\n\n```\nclaude mcp add-json --scope user nexus-work"],
    'claude.ai with the signed URL' => [StarAccessMode::SignedUrl, 'claude-ai', "Open Settings → Connectors, choose \"Add custom connector\" and paste this URL:\n\n```\n{url}\n```"],
    'Grok with OAuth' => [StarAccessMode::OAuth, 'grok', "Add this to ~/.grok/config.toml, keeping anything already in it:\n\n```\n[mcp_servers.nexus-work]\nurl = \"{url}\"\n```\n\n2. Then open /mcps in Grok, choose nexus-work and press i to sign in."],
]);

it('listens for the chosen client and turns green when the Star hears from it', function (StarAccessMode $mode, Closure $call): void {
    $this->travelTo('2026-10-03 12:00:00');
    $star = Star::factory()->for($this->user)->withAccessMode($mode)->create(['name' => 'Work']);
    $page = Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'cursor');

    expect(setupCheck($page->html()))->toBe([
        'state' => 'listening',
        'polls' => true,
        'text' => 'Waiting for a call from Cursor… '.($mode === StarAccessMode::SignedUrl
            ? 'Restart Cursor and ask it to use one of Work\'s tools.'
            : 'Restart Cursor and ask it to list its tools.').' This turns green as soon as Work hears from it. Troubleshoot',
    ]);

    $this->travel(20)->seconds();
    $call($star);
    $this->travel(5)->seconds();

    expect(setupCheck($page->call('$refresh')->html()))->toBe([
        'state' => 'heard',
        'polls' => false,
        'text' => 'Cursor reached Work 5 seconds ago It\'s set up. Its calls show in Activity. See Activity →',
    ]);
})->with([
    'an Activity entry from it' => [StarAccessMode::Token, fn (Star $star) => ActivityEntry::factory()->for($star->user)->create(['star_id' => $star->id, 'client_name' => 'Cursor'])],
    'a call through the signed URL' => [StarAccessMode::SignedUrl, fn (Star $star) => ActivityEntry::factory()->for($star->user)->create(['star_id' => $star->id, 'via' => StarAccessMode::SignedUrl, 'client_name' => null])],
    'a token being used' => [StarAccessMode::Token, fn (Star $star) => StarToken::factory()->for($star)->create(['name' => 'Laptop'])->markUsed()],
    'a connected app being used' => [StarAccessMode::OAuth, fn (Star $star) => StarOAuthClient::factory()->for($star)->approved()->create(['client_id' => Client::factory()->asPublic()->createOne(['name' => 'Cursor'])->id])->markUsed()],
]);

it('keeps listening through calls from before the page opened or from another client', function (): void {
    $this->travelTo('2026-10-03 12:00:00');
    $star = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $star->id, 'client_name' => 'Cursor', 'created_at' => now()->subSecond()]);
    StarToken::factory()->for($star)->create(['name' => 'Cursor', 'last_used_at' => now()->subSecond()]);
    $page = Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'cursor');

    $this->travel(20)->seconds();
    ActivityEntry::factory()->for($this->user)->create(['star_id' => $star->id, 'client_name' => 'Claude Code']);
    StarToken::factory()->for($star)->create(['name' => 'codex-laptop'])->markUsed();
    ActivityEntry::factory()->for($this->user)->create(['client_name' => 'Cursor']);

    expect(setupCheck($page->call('$refresh')->html())['state'])->toBe('listening');
});

it('stops listening after ten minutes, and listens again when asked', function (): void {
    $this->travelTo('2026-10-03 12:00:00');
    $star = Star::factory()->for($this->user)->create();
    $page = Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'grok');

    $this->travel(10)->minutes();

    expect(setupCheck($page->call('$refresh')->html()))->toBe([
        'state' => 'stopped',
        'polls' => false,
        'text' => 'No call from Grok yet Stopped listening after 10 minutes. Listen again once you\'ve set it up. Troubleshoot Listen again',
    ]);

    expect(setupCheck($page->call('listenAgain')->html()))->toMatchArray(['state' => 'listening', 'polls' => true]);
});

it('shows troubleshooting tips when asked', function (): void {
    $star = Star::factory()->for($this->user)->create(['slug' => 'work']);
    $page = Livewire::test('pages::stars.show', ['star' => $star])->set('client', 'codex');

    $page->assertDontSeeText('A revoked token is refused.')->toggle('showTips');

    $tips = array_map(fn (Element $tip): string => squished($tip->textContent), iterator_to_array(setupCard($page->html())->querySelectorAll('[data-setup-tips] li')));

    expect($tips)->toBe([
        'Codex reads the token from NEXUS_WORK_TOKEN when it starts. After adding it to ~/.zshrc, open a new terminal and restart Codex from there.',
        'A revoked token is refused. Check the token is still listed on the Access page, or create another.',
        'Check that Codex lists nexus-work among its MCP servers without an error.',
    ]);
});
