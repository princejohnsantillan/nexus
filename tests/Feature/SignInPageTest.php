<?php

declare(strict_types=1);

use Livewire\Livewire;

it('offers GitHub sign-in beside the picture of Nexus', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeHtml('<title>Sign in · Nexus</title>')
        ->assertSeeText(['Sign in to Nexus', 'New here? Signing in creates your account.', 'Continue with GitHub'])
        ->assertSee('href="'.route('auth.github').'"', escape: false)
        ->assertSee('data-star-chart', escape: false)
        ->assertSeeText(['One endpoint. Every AI client.', 'Each Star decides which of their tools Claude Code, Cursor or Codex can call.']);
});

it('uses the public layout without the app sidebar', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertDontSee('data-flux-sidebar', escape: false);
});

it('renders the sign-in component by name', function (): void {
    Livewire::test('pages::auth.sign-in')
        ->assertOk()
        ->assertSeeText('Continue with GitHub');
});
