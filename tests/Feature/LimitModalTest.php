<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\ReturnToStar;
use Dom\Element;
use Dom\HTMLDocument;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['nexus.plans.free.stars' => 2, 'nexus.plans.free.connections' => 10]);
});

/**
 * The name of the modal the control opens, or null when it opens none.
 */
function modalOpenedBy(?Element $control): ?string
{
    $trigger = $control?->closest('[data-flux-modal-trigger]')?->getAttribute('x-on:click') ?? '';

    return preg_match("/name: '([a-z-]+)'/", $trigger, $match) === 1 ? $match[1] : null;
}

/**
 * The first element on the page matching the selector.
 */
function pageElement(string $html, string $selector): ?Element
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector($selector);
}

/**
 * The text of the page's limit modal for "stars" or "connections", with its
 * whitespace collapsed, or null when the page has none.
 */
function limitModalText(string $html, string $for): ?string
{
    $modal = pageElement($html, "[data-limit-modal=\"{$for}\"]");

    return $modal instanceof Element ? trim((string) preg_replace('/\s+/', ' ', $modal->textContent ?? '')) : null;
}

it('opens the upgrade prompt from Create Star once a Free user has both Stars', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->count(2)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::stars.index')
        ->assertSeeText('2 / 2 Stars')
        ->assertDontSeeText('Star limit reached')
        ->html();

    expect(modalOpenedBy(pageElement($html, '[data-create-star]')))->toBe('star-limit')
        ->and(pageElement($html, '[data-create-star]')?->hasAttribute('disabled'))->toBeFalse();
    expect(limitModalText($html, 'stars'))->toBe('You\'ve used both Free Stars Free includes 2 Stars. Go Pro for as many as your agents need, or delete a Star you no longer use. Pro ₱499 / month or ₱4,999 / year Unlimited Stars Unlimited Connections Not now See Pro');
    expect(pageElement($html, '[data-limit-modal-upgrade]')?->getAttribute('href'))->toBe(route('billing.upgrade', ['period' => 'year']));
});

it('names how many Stars Free includes when the limit isn\'t two', function (int $limit, string $text): void {
    config(['nexus.plans.free.stars' => $limit]);
    $user = User::factory()->create();
    Star::factory()->for($user)->count($limit)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::stars.index')->html();

    expect(limitModalText($html, 'stars'))->toStartWith($text);
})->with([
    'one' => [1, 'You\'ve used your Free Star Free includes 1 Star. Go Pro'],
    'three' => [3, 'You\'ve used all 3 Free Stars Free includes 3 Stars. Go Pro'],
]);

it('opens the create form from Create Star, without the upgrade prompt, below the limit and on Pro', function (Closure $user, int $stars): void {
    $user = $user();
    Star::factory()->for($user)->count($stars)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::stars.index')->html();

    expect(modalOpenedBy(pageElement($html, '[data-create-star]')))->toBe('create-star')
        ->and(limitModalText($html, 'stars'))->toBeNull();
})->with([
    'Free with one Star' => [fn (): User => User::factory()->create(), 1],
    'Pro past the Free limit' => [fn (): User => User::factory()->pro()->create(), 5],
]);

it('still refuses a Star past the limit when another tab created one after the page opened', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->create();
    $this->actingAs($user);
    $page = Livewire::test('pages::stars.index');
    Star::factory()->for($user)->create();

    $page->set('name', 'Third')->call('create');

    $page->assertHasErrors(['limit' => 'Free includes 2 Stars. Go Pro for more, or delete one you no longer use.']);
    expect($user->stars()->count())->toBe(2);
});

it('opens the Connections prompt from every way to add one on the Connections page once a Free user has 10', function (): void {
    $user = User::factory()->create();
    Connection::factory()->for($user)->count(10)->create();
    $this->actingAs($user);

    $page = Livewire::test('pages::connections.index');
    $html = $page->html();
    $noMatch = $page->set('search', 'no such server')->html();

    $tiles = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('[data-connector]');
    $tileModals = [];
    foreach ($tiles as $tile) {
        $button = $tile->querySelector('button');
        $tileModals[$tile->getAttribute('data-connector') ?? ''] = $button?->hasAttribute('disabled') === true
            ? (str_contains($tile->textContent ?? '', 'Not available yet') ? 'unavailable' : 'disabled')
            : modalOpenedBy($button);
    }

    expect(modalOpenedBy(pageElement($html, '[data-add-connection]')))->toBe('connection-limit')
        ->and(modalOpenedBy(pageElement($noMatch, '[data-connect-by-url]')))->toBe('connection-limit');
    expect($tileModals)->toHaveKey('github', 'connection-limit')
        ->toHaveKey('custom', 'connection-limit')
        ->each->toBeIn(['connection-limit', 'unavailable']);
    expect(limitModalText($html, 'connections'))->toBe('You\'ve used all 10 Free Connections Free includes 10 Connections. Go Pro for as many as you need, or delete one you no longer use. Pro ₱499 / month or ₱4,999 / year Unlimited Stars Unlimited Connections Not now See Pro');
    $page->assertDontSeeText('Connection limit reached');
});

it('leads every way to add a Connection on the Connections page to the catalog below the limit and on Pro', function (Closure $user, int $connections): void {
    $user = $user();
    Connection::factory()->for($user)->count($connections)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::connections.index')->html();

    expect(pageElement($html, '[data-add-connection]')?->getAttribute('href'))->toBe('#add-more')
        ->and(pageElement($html, '[data-connector="github"] button')?->getAttribute('wire:click'))->toBe("startConnecting('github')")
        ->and(pageElement($html, '[data-connector="custom"] a')?->getAttribute('href'))->toBe(route('connections.add-custom'))
        ->and(limitModalText($html, 'connections'))->toBeNull();
})->with([
    'Free with 9' => [fn (): User => User::factory()->create(), 9],
    'Pro past the Free limit' => [fn (): User => User::factory()->pro()->create(), 12],
]);

it('opens the Connections prompt from "Add a connection" on a Star only at the limit', function (Closure $user, int $connections, ?string $modal): void {
    $user = $user();
    Connection::factory()->for($user)->count($connections)->create();
    $star = Star::factory()->for($user)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::stars.show', ['star' => $star])->html();
    $addConnection = pageElement($html, '[data-add-connection]');

    expect(modalOpenedBy($addConnection))->toBe($modal)
        ->and($addConnection?->getAttribute('href'))->toBe($modal === null ? ReturnToStar::addMoreUrl($star) : null)
        ->and(limitModalText($html, 'connections') !== null)->toBe($modal !== null);
})->with([
    'Free with 10' => [fn (): User => User::factory()->create(), 10, 'connection-limit'],
    'Free with 9' => [fn (): User => User::factory()->create(), 9, null],
    'Pro past the Free limit' => [fn (): User => User::factory()->pro()->create(), 12, null],
]);

it('opens the Connections prompt when the custom server form is submitted at the limit, in place of the callout', function (): void {
    $user = User::factory()->create();
    Connection::factory()->for($user)->count(10)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::connections.add-custom')
        ->assertDontSeeText('Connection limit reached')
        ->html();
    $form = pageElement($html, '[data-custom-connection-form]');

    expect($form?->getAttribute('x-on:submit.prevent'))->toBe("\$flux.modal('connection-limit').show()")
        ->and($form?->hasAttribute('wire:submit'))->toBeFalse()
        ->and(limitModalText($html, 'connections'))->toStartWith('You\'ve used all 10 Free Connections');
});

it('saves the custom server form as usual below the limit and on Pro', function (Closure $user, int $connections): void {
    $user = $user();
    Connection::factory()->for($user)->count($connections)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::connections.add-custom')->html();
    $form = pageElement($html, '[data-custom-connection-form]');

    expect($form?->getAttribute('wire:submit'))->toBe('save')
        ->and($form?->hasAttribute('x-on:submit.prevent'))->toBeFalse()
        ->and(limitModalText($html, 'connections'))->toBeNull();
})->with([
    'Free with 9' => [fn (): User => User::factory()->create(), 9],
    'Pro past the Free limit' => [fn (): User => User::factory()->pro()->create(), 12],
]);

it('opens the Connections prompt from the getting-started connect step when Free includes no Connections', function (): void {
    config(['nexus.plans.free.connections' => 0]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::stars.index')->html();

    expect(modalOpenedBy(pageElement($html, '[data-step="connect-server"] button')))->toBe('connection-limit')
        ->and(pageElement($html, '[data-step="connect-server"] a'))->toBeNull();
});
