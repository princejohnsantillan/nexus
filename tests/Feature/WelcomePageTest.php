<?php

declare(strict_types=1);

use Livewire\Livewire;

it('explains Nexus to a visitor and links to sign in', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeText('Connect your MCP servers once. Use them in every AI client.')
        ->assertSeeText(['Sign in', 'Get started'])
        ->assertSee('href="'.route('auth.sign-in').'"', escape: false);
});

it('pictures Connections flowing through a Star to the clients', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-star-chart', escape: false)
        ->assertSee('Connections to GitHub, Linear and Notion flow into a Star, which Claude Code, Cursor and Codex use.')
        ->assertSeeText(['Claude Code', 'Cursor', 'Codex']);
});

it('links the Terms, Privacy and Refund pages from the footer', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('href="'.route('legal.terms').'"', escape: false)
        ->assertSee('href="'.route('legal.privacy').'"', escape: false)
        ->assertSee('href="'.route('legal.refunds').'"', escape: false);
});

it('links the Pricing page from the header, next to Sign in', function (): void {
    $this->get(route('home'))
        ->assertSeeInOrder(['href="'.route('pricing').'"', 'Pricing', 'href="'.route('auth.sign-in').'"', 'Sign in'], escape: false);
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
