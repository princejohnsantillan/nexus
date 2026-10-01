<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\SwitchStarTools;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->toolset = resolve(StarToolset::class);
});

/**
 * The exposed name of each tool, mapped to whether it is on.
 *
 * @param  list<StarTool>  $tools
 * @return array<string, bool>
 */
function enabledByName(array $tools): array
{
    return collect($tools)->mapWithKeys(fn (StarTool $tool): array => [$tool->name => $tool->enabled])->all();
}

it('decides each tool without a switch by the Star\'s new-tool policy and the tool\'s read-only hint', function (NewToolPolicy $policy, array $expected): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'read_page', 'read_only' => true]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'write_page', 'read_only' => false]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'unstated', 'read_only' => null]);
    $star = Star::factory()->for($this->user)->withPolicy($policy)->including($connection)->create();

    expect(enabledByName($this->toolset->tools($star)))->toBe($expected)
        ->and(collect($this->toolset->tools($star))->every(fn (StarTool $tool): bool => $tool->followsPolicy()))->toBeTrue();
})->with([
    'read-only on' => [NewToolPolicy::ReadOnly, ['wiki__read_page' => true, 'wiki__unstated' => false, 'wiki__write_page' => false]],
    'all on' => [NewToolPolicy::All, ['wiki__read_page' => true, 'wiki__unstated' => true, 'wiki__write_page' => true]],
    'all off' => [NewToolPolicy::None, ['wiki__read_page' => false, 'wiki__unstated' => false, 'wiki__write_page' => false]],
]);

it('lets the user\'s own switch override the policy either way', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'read_page', 'read_only' => true]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'write_page', 'read_only' => false]);
    $star = Star::factory()->for($this->user)->including($connection)->create();

    resolve(SwitchStarTools::class)->handle($star, $connection, false, ['read_page']);
    resolve(SwitchStarTools::class)->handle($star, $connection, true, ['write_page']);

    $tools = $this->toolset->tools($star);

    expect(enabledByName($tools))->toBe(['wiki__read_page' => false, 'wiki__write_page' => true])
        ->and(array_map(fn (StarTool $tool): ?bool => $tool->switch, $tools))->toBe([false, true]);
});

it('lists Connections by name and their tools by name, named handle__tool', function (): void {
    $zulu = Connection::factory()->for($this->user)->create(['name' => 'Zulu', 'handle' => 'zulu']);
    $alpha = Connection::factory()->for($this->user)->create(['name' => 'Alpha', 'handle' => 'alpha']);
    ConnectionTool::factory()->for($zulu)->create(['name' => 'search', 'read_only' => true]);
    ConnectionTool::factory()->for($alpha)->create(['name' => 'write', 'read_only' => true]);
    ConnectionTool::factory()->for($alpha)->create(['name' => 'fetch', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($zulu, $alpha)->create();

    expect(array_map(fn (StarTool $tool): string => $tool->name, $this->toolset->tools($star)))->toBe(['alpha__fetch', 'alpha__write', 'zulu__search']);
});

it('only includes the Star\'s own Connections', function (): void {
    $included = Connection::factory()->for($this->user)->create(['handle' => 'included']);
    $leftOut = Connection::factory()->for($this->user)->create(['handle' => 'left-out']);
    ConnectionTool::factory()->for($included)->create(['name' => 'search', 'read_only' => true]);
    ConnectionTool::factory()->for($leftOut)->create(['name' => 'search', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($included)->create();
    Star::factory()->for($this->user)->including($leftOut)->create();

    expect(enabledByName($this->toolset->enabledTools($star)))->toBe(['included__search' => true])
        ->and($this->toolset->enabledTool($star, 'left-out__search'))->toBeNull();
});

it('returns only the enabled tools, in order', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'read_page', 'read_only' => true]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'write_page', 'read_only' => false]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'ask', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($connection)->create();

    expect(array_map(fn (StarTool $tool): string => $tool->name, $this->toolset->enabledTools($star)))->toBe(['wiki__ask', 'wiki__read_page']);
});

it('stops a tool being on by default when its server stops declaring it read-only', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'edit_page', 'annotations' => ['readOnlyHint' => true]]]);
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($connection)->create();
    resolve(RefreshCatalog::class)->handle($connection);

    expect($this->toolset->enabledTool($star, 'wiki__edit_page'))->not->toBeNull();

    $server->withTools([['name' => 'edit_page', 'annotations' => ['readOnlyHint' => false]]]);
    resolve(RefreshCatalog::class)->handle($connection);

    expect(enabledByName($this->toolset->tools($star)))->toBe(['wiki__edit_page' => false])
        ->and($this->toolset->enabledTool($star, 'wiki__edit_page'))->toBeNull();
});

it('keeps the user\'s switches through catalog refreshes, even when a tool changes or comes back', function (): void {
    $server = FakeMcpServer::at()->withTools([
        ['name' => 'read_page', 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'write_page', 'annotations' => ['readOnlyHint' => false]],
    ]);
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($connection)->create();
    resolve(RefreshCatalog::class)->handle($connection);
    resolve(SwitchStarTools::class)->handle($star, $connection, false, ['read_page']);
    resolve(SwitchStarTools::class)->handle($star, $connection, true, ['write_page']);

    $server->withTools([
        ['name' => 'read_page', 'description' => 'Now with a description.', 'annotations' => ['readOnlyHint' => true]],
    ]);
    resolve(RefreshCatalog::class)->handle($connection);

    expect(enabledByName($this->toolset->tools($star)))->toBe(['wiki__read_page' => false]);

    $server->withTools([
        ['name' => 'read_page', 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'write_page', 'annotations' => ['readOnlyHint' => false]],
    ]);
    resolve(RefreshCatalog::class)->handle($connection);

    expect(enabledByName($this->toolset->tools($star)))->toBe(['wiki__read_page' => false, 'wiki__write_page' => true]);
});

it('finds an enabled tool by its exposed name, with its Connection and catalog entry', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'deep-wiki']);
    $tool = ConnectionTool::factory()->for($connection)->create(['name' => 'read__wiki.contents', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($connection)->create();

    $found = $this->toolset->enabledTool($star, 'deep-wiki__read__wiki.contents');

    expect($found)->toBeInstanceOf(StarTool::class)
        ->and($found?->name)->toBe('deep-wiki__read__wiki.contents')
        ->and($found?->connection->is($connection))->toBeTrue()
        ->and($found?->tool->is($tool))->toBeTrue();
});

it('finds no tool for a name that is off, unknown or malformed', function (string $name): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'read_page', 'read_only' => true]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'write_page', 'read_only' => false]);
    $star = Star::factory()->for($this->user)->including($connection)->create();

    expect($this->toolset->enabledTool($star, $name))->toBeNull();
})->with([
    'switched off by the policy' => 'wiki__write_page',
    'unknown tool' => 'wiki__delete_page',
    'unknown handle' => 'other__read_page',
    'no separator' => 'read_page',
    'empty' => '',
    'handle only' => 'wiki__',
    'wrong case' => 'WIKI__read_page',
]);

it('finds no tool the user switched off', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'read_page', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($connection)->create();

    resolve(SwitchStarTools::class)->handle($star, $connection, false, ['read_page']);

    expect($this->toolset->enabledTool($star, 'wiki__read_page'))->toBeNull()
        ->and($this->toolset->enabledTools($star))->toBe([]);
});

it('exposes the definition exactly as the server sent it, with the exposed name', function (): void {
    FakeMcpServer::at()->withTools('[{"name":"search","description":"Find things.","inputSchema":{"type":"object","properties":{},"maximum":18446744073709551615,"other":1e400},"annotations":{"readOnlyHint":true,"name":"not this one"}}]');
    $connection = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($connection)->create();
    resolve(RefreshCatalog::class)->handle($connection);

    expect($this->toolset->enabledTool($star, 'wiki__search')?->definition())
        ->toBe('{"name":"wiki__search","description":"Find things.","inputSchema":{"type":"object","properties":{},"maximum":18446744073709551615,"other":1e400},"annotations":{"readOnlyHint":true,"name":"not this one"}}');
});

it('labels the tools of Connections of the same service, and only those', function (array $first, array $second, bool $siblings): void {
    $one = Connection::factory()->for($this->user)->create(['name' => 'One', 'handle' => 'one', ...$first]);
    $two = Connection::factory()->for($this->user)->create(['name' => 'Two', 'handle' => 'two', ...$second]);
    ConnectionTool::factory()->for($one)->create(['name' => 'search']);
    ConnectionTool::factory()->for($two)->create(['name' => 'search']);
    $star = Star::factory()->for($this->user)->including($one, $two)->create();

    expect(array_map(fn (StarTool $tool): bool => str_starts_with($tool->description() ?? '', 'From '), $this->toolset->tools($star)))
        ->toBe([$siblings, $siblings]);
})->with([
    'the same connector' => [['connector_key' => 'github'], ['connector_key' => 'github'], true],
    'custom servers on the same host' => [['url' => 'https://mcp.deepwiki.com/mcp'], ['url' => 'https://MCP.DeepWiki.com/sse'], true],
    'custom servers on other hosts' => [['url' => 'https://mcp.deepwiki.com/mcp'], ['url' => 'https://mcp.example.com/mcp'], false],
    'different connectors' => [['connector_key' => 'github'], ['connector_key' => 'linear'], false],
    'a connector and a custom server on its host' => [['connector_key' => 'github', 'url' => 'https://api.githubcopilot.com/mcp/'], ['url' => 'https://api.githubcopilot.com/mcp/'], false],
]);

it('labels a tool looked up by its exposed name as it does in the list', function (): void {
    $work = Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub', 'handle' => 'github', 'account_identity' => 'octocat']);
    $personal = Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub 2', 'handle' => 'github-2']);
    ConnectionTool::factory()->for($work)->create(['name' => 'get_me']);
    ConnectionTool::factory()->for($personal)->create(['name' => 'get_me']);
    $star = Star::factory()->for($this->user)->including($work, $personal)->create();

    $listed = collect($this->toolset->tools($star))->firstWhere('name', 'github__get_me');

    expect($this->toolset->tool($star, 'github__get_me')?->definition())->toBe($listed?->definition())
        ->and($listed?->description())->toStartWith('From GitHub · octocat');
});
