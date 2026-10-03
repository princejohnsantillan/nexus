<?php

declare(strict_types=1);

use App\Models\User;
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

it('offers GitHub and an email code, and no Google', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeTextInOrder(['Continue with GitHub', 'or use your email', 'Email', 'Email me a sign-in code'])
        ->assertDontSee('Google');
});

it('says continuing agrees to the Terms and Privacy Policy, and links them and the source', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeText('Nexus is open source. By continuing you agree to the Terms and Privacy Policy.')
        ->assertSeeHtmlInOrder([
            'href="https://github.com/princejohnsantillan/nexus"', '>open source</a>',
            'href="'.route('legal.terms').'"', '>Terms</a>',
            'href="'.route('legal.privacy').'"', '>Privacy Policy</a>',
        ]);
});

it('has no Google sign-in to start, return to or add in Settings', function (bool $signedIn, string $path): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->signsInWithGitHub('octocat')->create());
    }

    $this->get($path)->assertNotFound();
})->with([
    'a guest' => false,
    'a signed-in user' => true,
])->with([
    '/auth/google',
    '/auth/google/callback?code=4/0AX4XfWh&state=abc',
    '/settings/sign-in-methods/google',
]);

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
