<?php

declare(strict_types=1);

use App\Models\User;

it('sends guests to the welcome page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('home'));
})->with([
    'stars' => 'stars.index',
    'connections' => 'connections.index',
    'activity' => 'activity.index',
    'settings' => 'settings.index',
]);

it('sends signed-in visitors from the welcome page to the app', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertRedirect(route('stars.index'));
});
