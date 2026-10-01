<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('lists each prompt with its title, description and arguments', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    ConnectionPrompt::factory()->for($connection)->definedAs('{"name":"summarize","title":"Summarize a page","description":"Summarizes one wiki page.","arguments":[{"name":"page","description":"The page to summarize.","required":true},{"name":"length","title":"Length"},{"description":"No name, so not shown."}]}')->create();
    ConnectionPrompt::factory()->for($connection)->definedAs('{"name":"explain"}')->create();

    $this->get(route('connections.prompts', $connection))
        ->assertOk()
        ->assertSee('<title>Connection prompts · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Overview', 'Tools', 'Prompts'])
        ->assertSeeTextInOrder(['Prompt', 'Arguments'])
        ->assertSeeTextInOrder(['explain', 'None'])
        ->assertSeeTextInOrder(['Summarize a page', 'summarize', 'Summarizes one wiki page.', 'page', 'Required', 'The page to summarize.', 'length', 'Optional', 'Length'])
        ->assertDontSeeText('No name, so not shown.');
});

it('says when the catalog hasn\'t been loaded yet', function (): void {
    $connection = Connection::factory()->for($this->user)->create();

    Livewire::test('pages::connections.prompts', ['connection' => $connection])
        ->assertSeeText('No prompts')
        ->assertSeeText('Nexus hasn\'t loaded this Connection\'s catalog yet.')
        ->assertSee(route('connections.show', $connection));
});

it('says when the server listed no prompts', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();

    Livewire::test('pages::connections.prompts', ['connection' => $connection])
        ->assertSeeText('The server listed no prompts at the last refresh.');
});

it('escapes the titles, descriptions and arguments servers send', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create();
    ConnectionPrompt::factory()->for($connection)->definedAs('{"name":"x","title":"<img src=x onerror=alert(1)>","description":"<script>alert(\"prompt\")</script>","arguments":[{"name":"<b>arg</b>","description":"<i>desc</i>"}]}')->create();

    $this->get(route('connections.prompts', $connection))
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertDontSee('<script>alert("prompt")</script>', escape: false)
        ->assertDontSee('<b>arg</b>', escape: false)
        ->assertDontSee('<i>desc</i>', escape: false);
});
