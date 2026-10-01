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

it('shows an empty state with a way to add the first Connection', function (): void {
    Livewire::test('pages::connections.index')
        ->assertOk()
        ->assertSeeText('No Connections yet')
        ->assertSeeText('Add your first connection')
        ->assertSee(route('connections.add'));
});

it('lists the user\'s Connections with their handle, status, tool count and last refresh', function (): void {
    $this->travelTo(now()->subHours(3), function (): void {
        $deepwiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'deepwiki', 'description' => 'reading docs']);
        ConnectionTool::factory()->for($deepwiki)->count(3)->create();
    });
    Connection::factory()->for($this->user)->failed()->create(['name' => 'Broken', 'handle' => 'broken']);

    Livewire::test('pages::connections.index')
        ->assertSeeTextInOrder(['Broken', 'broken', 'Error', '0', 'Never'])
        ->assertSeeTextInOrder(['DeepWiki', 'Use for: reading docs', 'deepwiki', 'Connected', '3', '3 hours ago'])
        ->assertSee(route('connections.show', Connection::query()->where('handle', 'deepwiki')->sole()));
});

it('shows only the user\'s own Connections', function (): void {
    Connection::factory()->create(['name' => 'Someone else\'s server']);

    Livewire::test('pages::connections.index')
        ->assertSeeText('No Connections yet')
        ->assertDontSeeText('Someone else\'s server');
});

it('escapes the names users give their Connections', function (): void {
    Connection::factory()->for($this->user)->create(['name' => '<script>alert("name")</script>']);

    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert("name")</script>', escape: false);
});
