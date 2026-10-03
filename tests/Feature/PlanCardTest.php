<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;

beforeEach(function (): void {
    config(['nexus.plans.free.stars' => 2]);
});

/**
 * The sidebar's plan card on the page, or null when it shows none.
 */
function planCard(string $html): ?Element
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-plan-card]');
}

/**
 * The element's text with its whitespace collapsed.
 */
function textOf(?Element $element): string
{
    return trim((string) preg_replace('/\s+/', ' ', $element?->textContent ?? ''));
}

it('shows a Free user their Stars against the limit and a way to upgrade', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->create();
    Star::factory()->count(3)->create();
    $this->actingAs($user);

    $card = planCard($this->get(route('stars.index'))->assertOk()->getContent());

    expect($card?->getAttribute('data-plan-card'))->toBe('free')
        ->and(textOf($card))->toBe('Free plan 1 / 2 Stars Go Pro for unlimited Stars, Connections and tool calls. Upgrade to Pro')
        ->and($card?->querySelector('[data-plan-card-usage]')?->hasAttribute('data-at-limit'))->toBeFalse()
        ->and($card?->querySelector('a')?->getAttribute('href'))->toBe(route('billing.upgrade'));
});

it('shows a Free user at the Star limit in amber', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->count(2)->create();
    $this->actingAs($user);

    $card = planCard($this->get(route('connections.index'))->assertOk()->getContent());

    expect(textOf($card?->querySelector('[data-plan-card-usage]')))->toBe('2 / 2 Stars')
        ->and($card?->querySelector('[data-plan-card-usage]')?->hasAttribute('data-at-limit'))->toBeTrue();
});

it('shows nothing on Pro until its last 7 days', function (): void {
    $this->actingAs(User::factory()->proEndingIn(8)->create());

    expect(planCard($this->get(route('stars.index'))->assertOk()->getContent()))->toBeNull();
});

it('says when Pro ends in its last 7 days, with a way to extend it', function (int $days, string $heading): void {
    $this->actingAs(User::factory()->proEndingIn($days)->create());

    $card = planCard($this->get(route('activity.index'))->assertOk()->getContent());

    expect($card?->getAttribute('data-plan-card'))->toBe('pro-ending')
        ->and(textOf($card))->toBe("{$heading} Extend it to keep adding Stars and Connections past the Free limits. Extend Pro")
        ->and($card?->querySelector('a')?->getAttribute('href'))->toBe(route('billing.upgrade'));
})->with([
    '7 days' => [7, 'Pro ends in 7 days'],
    '1 day' => [1, 'Pro ends in 1 day'],
]);

it('shows the Free card again once Pro has ended', function (): void {
    $this->actingAs(User::factory()->proEnded()->create());

    expect(planCard($this->get(route('stars.index'))->assertOk()->getContent())?->getAttribute('data-plan-card'))->toBe('free');
});

it('leaves the card off the Upgrade page it leads to', function (): void {
    $this->actingAs(User::factory()->create());

    expect(planCard($this->get(route('billing.upgrade'))->assertOk()->getContent()))->toBeNull()
        ->and(planCard($this->get(route('billing.index'))->assertOk()->getContent()))->not->toBeNull();
});

it('puts the card under "Action required", above the profile', function (): void {
    $user = User::factory()->create();
    Connection::factory()->for($user)->failed()->create();
    $this->actingAs($user);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSeeInOrder(['data-action-required', 'data-plan-card="free"', 'data-flux-sidebar-profile'], escape: false);
});
