<?php

declare(strict_types=1);

use Livewire\Livewire;

it('explains Nexus to a visitor', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeText('Connect your MCP servers once. Use them in every AI client.')
        ->assertSeeText('Sign in with GitHub');
});

it('uses the public layout without the app sidebar', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-flux-sidebar', escape: false);
});

it('renders the welcome component by name', function (): void {
    Livewire::test('pages::welcome')
        ->assertOk()
        ->assertSeeText('Bundle them into Stars');
});
