<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

dataset('legal pages', [
    'terms' => ['legal.terms', 'Terms of Service'],
    'privacy' => ['legal.privacy', 'Privacy Policy'],
    'refunds' => ['legal.refunds', 'Refund Policy'],
]);

it('shows each legal page to a guest, with a way to sign in', function (string $route, string $title): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSeeHtml("<title>{$title} · Nexus</title>")
        ->assertSeeText([$title, 'Last updated'])
        ->assertSee('href="'.route('auth.sign-in').'"', escape: false)
        ->assertDontSee('data-flux-sidebar', escape: false);
})->with('legal pages');

it('shows each legal page to a signed-in user, with a way back to their Stars', function (string $route, string $title): void {
    $this->actingAs(User::factory()->create());

    $this->get(route($route))
        ->assertOk()
        ->assertSeeText([$title, 'Go to Stars'])
        ->assertSee('href="'.route('stars.index').'"', escape: false)
        ->assertDontSee('href="'.route('auth.sign-in').'"', escape: false);
})->with('legal pages');

it('gives the contact address from the settings', function (string $route): void {
    config(['nexus.contact_email' => 'help@nexus.example']);

    $this->get(route($route))
        ->assertOk()
        ->assertSee('href="mailto:help@nexus.example"', escape: false)
        ->assertSeeText('help@nexus.example');
})->with('legal pages');

it('says each page is a draft until the owner has reviewed them', function (string $route): void {
    config(['nexus.legal.reviewed' => false]);

    $this->get(route($route))
        ->assertOk()
        ->assertSeeText('This draft is reviewed before Nexus takes payments.');
})->with('legal pages');

it('drops the draft note once the pages are reviewed', function (string $route): void {
    config(['nexus.legal.reviewed' => true]);

    $this->get(route($route))
        ->assertOk()
        ->assertDontSeeText('This draft is reviewed before Nexus takes payments.');
})->with('legal pages');

it('links each legal page to the others from the footer', function (string $route): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('href="'.route('legal.terms').'"', escape: false)
        ->assertSee('href="'.route('legal.privacy').'"', escape: false)
        ->assertSee('href="'.route('legal.refunds').'"', escape: false)
        ->assertSee('aria-current="page"', escape: false);
})->with('legal pages');

it('renders the legal components by name', function (string $route, string $title): void {
    Livewire::test('pages::'.$route)
        ->assertOk()
        ->assertSeeText($title);
})->with('legal pages');
