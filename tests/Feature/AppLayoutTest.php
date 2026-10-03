<?php

declare(strict_types=1);

use App\Models\SignInIdentity;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->signsInWithGitHub('octocat')->create(['name' => 'Mona Lisa Octocat']);

    $this->actingAs($this->user);
});

dataset('app pages', [
    'stars' => ['stars.index', 'Stars'],
    'connections' => ['connections.index', 'Connections'],
    'activity' => ['activity.index', 'Activity'],
]);

it('renders each app page inside the sidebar layout', function (string $route, string $title): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee("<title>{$title} · Nexus</title>", escape: false)
        ->assertSee('data-flux-sidebar', escape: false)
        ->assertSee(route('stars.index'))
        ->assertSee(route('connections.index'))
        ->assertSee(route('activity.index'));
})->with('app pages');

it('marks the current page in the sidebar', function (string $route, string $title): void {
    $html = $this->get(route($route))->assertOk()->getContent();

    expect($html)->toMatch('/<a[^>]*href="'.preg_quote(route($route), '/').'"[^>]*data-current/');
})->with('app pages');

it('offers the appearance switch in the profile menu', function (): void {
    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee('x-model="$flux.appearance"', escape: false)
        ->assertSeeTextInOrder(['Appearance', 'Light', 'Dark', 'System']);
});

it('shows the signed-in user with settings and sign out in the profile menu', function (): void {
    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee($this->user->avatar_url)
        ->assertSeeText(['Mona Lisa Octocat', '@octocat', 'Settings', 'Sign out'])
        ->assertSee(route('settings.index'))
        ->assertSee('action="'.route('logout').'"', escape: false);
});

it('shows the email address of a user who does not sign in with GitHub', function (): void {
    $this->actingAs(User::factory()
        ->has(SignInIdentity::factory()->email('ada@example.com'), 'signInIdentities')
        ->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']));

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSeeText(['Ada Lovelace', 'ada@example.com'])
        ->assertDontSeeText('@octocat');
});
