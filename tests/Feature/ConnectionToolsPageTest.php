<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\ActivityStatus;
use App\Enums\NewToolPolicy;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('lists each tool with its title, description and the four hints as yes, no or not stated', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    ConnectionTool::factory()->for($connection)->create([
        'name' => 'delete_page',
        'title' => 'Delete a page',
        'description' => 'Deletes a page for good.',
        'read_only' => false,
        'destructive' => true,
        'idempotent' => true,
        'open_world' => null,
    ]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'search', 'title' => null, 'description' => null]);

    $this->get(route('connections.tools', $connection))
        ->assertOk()
        ->assertSee('<title>Connection tools · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Tool', 'Read-only', 'Destructive', 'Idempotent', 'Open-world'])
        ->assertSeeTextInOrder(['Delete a page', 'delete_page', 'Deletes a page for good.', 'No', 'Yes', 'Yes', 'Not stated'])
        ->assertSeeTextInOrder(['search', 'Not stated', 'Not stated', 'Not stated', 'Not stated']);
});

it('says when the tools haven\'t been loaded yet', function (): void {
    $connection = Connection::factory()->for($this->user)->create();

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->assertSeeText('No tools')
        ->assertSeeText('Nexus hasn\'t loaded this Connection\'s tools yet.')
        ->assertSee(route('connections.show', $connection));
});

it('says when the server listed no tools', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->assertSeeText('The server listed no tools at the last refresh.');
});

it('escapes the titles and descriptions servers send', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    ConnectionTool::factory()->for($connection)->create(['title' => '<img src=x onerror=alert(1)>', 'description' => '<script>alert("tool")</script>']);

    $this->get(route('connections.tools', $connection))
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertDontSee('<script>alert("tool")</script>', escape: false);
});

it('opens a tool\'s details in a flyout from its name: its names, title, description, hints, parameters, Stars and recent calls', function (): void {
    $this->travelTo('2026-10-03 09:45:00');
    $connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $tool = ConnectionTool::factory()->for($connection)->create([
        'name' => 'read_page',
        'title' => 'Read a page',
        'description' => "Reads one wiki page.\n\nGive it the page's path.",
        'definition' => '{"name":"read_page","inputSchema":{"type":"object","properties":{"page":{"type":"string","description":"The page to read."}},"required":["page"]}}',
        'read_only' => true,
        'destructive' => false,
        'idempotent' => null,
        'open_world' => true,
    ]);
    $work = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Work']);
    ActivityEntry::factory()->through($work, $connection, 'read_page')->withStatus(ActivityStatus::Timeout)->create(['duration_ms' => 55012, 'created_at' => now()->subMinutes(5)]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->assertSeeHtml('wire:click="showToolDetails('.$tool->id.')"')
        ->call('showToolDetails', $tool->id)
        ->assertDispatched('modal-show', name: 'tool-details')
        ->assertSeeTextInOrder([
            'wiki__read_page', 'Read a page',
            'Description', 'Reads one wiki page.', 'Give it the page\'s path.',
            'Server\'s name', 'read_page', 'Connection', 'DeepWiki', 'wiki',
            'Read-only', 'Yes', 'Destructive', 'No', 'Idempotent', 'Not stated', 'Open-world', 'Yes',
            'Parameters', 'page', 'string', 'Required', 'The page to read.',
            'In your Stars', 'Work', 'Policy', 'On',
            'Recent calls', 'Work', '5 minutes ago', 'Timed out', '55.0 s',
        ]);
});

it('lists a tool\'s parameters from its stored schema, showing nested objects as "object"', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $tool = ConnectionTool::factory()->for($connection)->create(['definition' => <<<'JSON'
        {"name":"search","inputSchema":{"type":"object","properties":{
            "query":{"type":"string","description":"What to look for."},
            "limit":{"type":"integer","title":"Most results"},
            "filters":{"type":"object","properties":{"owner":{"type":"string","description":"Nested, so not listed."}}},
            "tags":{"type":"array","items":{"type":"string"}},
            "cursor":{"type":["string","null"]},
            "page":{"anyOf":[{"type":"integer"},{"type":"string"}]},
            "repo":{"oneOf":[{"type":"string"},{"type":"array","items":{"type":"string"}},{}]},
            "options":{"properties":{"deep":{"type":"boolean"}}},
            "anything":{}
        },"required":["query","tags"]}}
        JSON]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder([
            'Parameters',
            'query', 'string', 'Required', 'What to look for.',
            'limit', 'integer', 'Optional', 'Most results',
            'filters', 'object', 'Optional',
            'tags', 'string[]', 'Required',
            'cursor', 'string | null', 'Optional',
            'page', 'integer | string', 'Optional',
            'repo', 'string | string[]', 'Optional',
            'options', 'object', 'Optional',
            'anything', 'any', 'Optional',
            'In your Stars',
        ])
        ->assertDontSeeText('Nested, so not listed.');
});

it('shows odd or missing schemas without breaking', function (string $definition, array $shown): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $tool = ConnectionTool::factory()->for($connection)->create(['definition' => $definition]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder(['Parameters', ...$shown, 'In your Stars']);
})->with([
    'no input schema' => ['{"name":"odd"}', ['No parameters']],
    'a schema that isn\'t an object' => ['{"name":"odd","inputSchema":"object"}', ['No parameters']],
    'no properties' => ['{"name":"odd","inputSchema":{"type":"object"}}', ['No parameters']],
    'properties as a list' => ['{"name":"odd","inputSchema":{"type":"object","properties":[]}}', ['No parameters']],
    'a property without a name' => ['{"name":"odd","inputSchema":{"type":"object","properties":{"":{"type":"string"}}}}', ['No parameters']],
    'a property that isn\'t a schema, and required that isn\'t a list' => ['{"name":"odd","inputSchema":{"type":"object","properties":{"flag":true},"required":"flag"}}', ['flag', 'any', 'Optional']],
]);

it('says which of the user\'s Stars have the tool on, and whether the policy or their own switch decides', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $tool = ConnectionTool::factory()->for($connection)->create(['name' => 'search', 'read_only' => true]);
    Star::factory()->for($this->user)->including($connection)->create(['name' => 'Work']);
    Star::factory()->for($this->user)->including($connection)->create(['name' => 'Locked', 'new_tool_policy' => NewToolPolicy::None]);
    $switched = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Switched']);
    resolve(SwitchStarTools::class)->handle($switched, $connection, false, ['search']);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder([
            'In your Stars',
            'Locked', 'Policy', 'Off',
            'Switched', 'Your choice', 'Off',
            'Work', 'Policy', 'On',
            'Recent calls',
        ]);
});

it('lists only the user\'s own Stars and calls', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create(['handle' => 'wiki']);
    $tool = ConnectionTool::factory()->for($connection)->create(['name' => 'read_page']);
    $mine = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Mine']);
    Star::factory()->for($this->user)->create(['name' => 'Without it']);
    ActivityEntry::factory()->through($mine, $connection, 'read_page')->create(['duration_ms' => 412]);
    ActivityEntry::factory()->through($mine, $connection, 'write_page')->create(['duration_ms' => 777]);
    $someoneElse = User::factory()->create();
    Star::factory()->for($someoneElse)->including($connection)->create(['name' => 'Theirs']);
    $theirWiki = Connection::factory()->for($someoneElse)->create(['handle' => 'wiki']);
    $theirStar = Star::factory()->for($someoneElse)->including($theirWiki)->create(['name' => 'Their calls']);
    ActivityEntry::factory()->through($theirStar, $theirWiki, 'read_page')->create(['duration_ms' => 999]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder(['In your Stars', 'Mine', 'Recent calls', 'Mine', '412 ms'])
        ->assertDontSeeText('Without it')
        ->assertDontSeeText('Theirs')
        ->assertDontSeeText('Their calls')
        ->assertDontSeeText('777 ms')
        ->assertDontSeeText('999 ms');
});

it('shows the tool\'s five latest calls, newest first, including those of deleted Stars', function (): void {
    $this->travelTo('2026-10-03 09:45:00');
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $tool = ConnectionTool::factory()->for($connection)->create(['name' => 'search']);
    $star = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Work']);

    foreach (range(1, 5) as $minutesAgo) {
        ActivityEntry::factory()->through($star, $connection, 'search')->create(['duration_ms' => 100 + $minutesAgo, 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    ActivityEntry::factory()->through($star, $connection, 'search')->create(['star_id' => null, 'duration_ms' => 50, 'created_at' => now()->subSeconds(30)]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder(['Recent calls', 'Deleted Star', '50 ms', '101 ms', '102 ms', '103 ms', '104 ms'])
        ->assertDontSeeText('105 ms');
});

it('says when a tool is in no Star and has no calls', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki']);
    $tool = ConnectionTool::factory()->for($connection)->create();

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertSeeTextInOrder([
            'In no Star yet', 'Add DeepWiki to a Star to let its clients use this tool.',
            'No calls yet', 'Calls from the last 30 days appear here.',
        ]);
});

it('refuses details of a tool the Connection doesn\'t have', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $otherConnectionsTool = ConnectionTool::factory()->for(Connection::factory()->for($this->user))->create();
    $someoneElsesTool = ConnectionTool::factory()->create();

    foreach ([$otherConnectionsTool, $someoneElsesTool] as $tool) {
        Livewire::test('pages::connections.tools', ['connection' => $connection])
            ->call('showToolDetails', $tool->id)
            ->assertNotFound();
    }
});

it('escapes the parameters servers send in the details flyout', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $tool = ConnectionTool::factory()->for($connection)->create([
        'definition' => '{"name":"odd","inputSchema":{"type":"object","properties":{"<script>alert(1)</script>":{"type":"<img src=x onerror=alert(2)>","description":"<b onmouseover=alert(3)>hi</b>"}}}}',
    ]);

    Livewire::test('pages::connections.tools', ['connection' => $connection])
        ->call('showToolDetails', $tool->id)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img src=x onerror=alert(2)>')
        ->assertDontSeeHtml('<b onmouseover=alert(3)>')
        ->assertSeeText('<script>alert(1)</script>');
});
