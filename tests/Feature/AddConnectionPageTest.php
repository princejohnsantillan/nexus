<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('offers a custom MCP server card that leads to the custom form', function (): void {
    $this->get(route('connections.add'))
        ->assertOk()
        ->assertSee('<title>Add connection · Nexus</title>', escape: false)
        ->assertSeeText('Custom MCP server')
        ->assertSee(route('connections.add-custom'));
});

it('explains the limit once the user has as many Connections as an account may', function (): void {
    config(['nexus.limits.connections_per_user' => 2]);
    Connection::factory()->for($this->user)->count(2)->create();

    Livewire::test('pages::connections.add')
        ->assertSeeText('Connection limit reached')
        ->assertSeeText('You have 2 Connections, the most an account can have. Delete one to add another.')
        ->assertDontSee(route('connections.add-custom'));
});
