<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

it('sends guests to sign in', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('auth.sign-in'));
})->with([
    'stars' => 'stars.index',
    'connections' => 'connections.index',
    'add connection' => 'connections.add',
    'custom server' => 'connections.add-custom',
    'activity' => 'activity.index',
    'settings' => 'settings.index',
]);

it('brings a guest back to the page they were going to once they sign in', function (): void {
    $this->get(route('activity.index'))->assertRedirect(route('auth.sign-in'));
    $this->get(route('auth.sign-in'))->assertOk();

    Socialite::fake('github', SocialiteUser::fake(['id' => 583231, 'nickname' => 'octocat', 'name' => 'Mona Lisa Octocat']));

    $this->get(route('auth.github.callback'))->assertRedirect(route('activity.index'));
    $this->assertAuthenticated();
});

it('sends signed-in visitors from the welcome and sign-in pages to the app', function (string $route): void {
    $this->actingAs(User::factory()->create());

    $this->get(route($route))->assertRedirect(route('stars.index'));
})->with(['welcome' => 'home', 'sign-in' => 'auth.sign-in']);
