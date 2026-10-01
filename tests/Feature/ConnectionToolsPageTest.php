<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionTool;
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
