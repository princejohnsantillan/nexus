<?php

declare(strict_types=1);

use App\Models\User;

it('signs the user out and returns to the welcome page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();

    $this->get(route('home'))->assertOk()->assertSeeText("You're signed out.");
});

it('sends a guest who signs out to sign in', function (): void {
    $this->post(route('logout'))->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
});
