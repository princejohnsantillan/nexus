<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'nexus.plans.free' => ['stars' => 2, 'connections' => 10, 'tool_calls_per_week' => 3000],
        'nexus.plans.pro.prices' => ['month' => 49900, 'year' => 499900],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('offers Pro yearly first, beside Free as the user\'s plan', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('billing.upgrade'))
        ->assertOk()
        ->assertSee('<title>Upgrade · Nexus</title>', escape: false)
        ->assertSeeInOrder([route('billing.index'), 'Billing', 'Upgrade'], escape: false)
        ->assertSeeText('No more counting Stars. Bundle every server you use into as many endpoints as your agents need.');

    Livewire::test('pages::billing.upgrade')
        ->assertSet('period', 'year')
        ->assertSeeTextInOrder(['Go Pro', 'Monthly', 'Yearly', 'Save ₱989'])
        ->assertSeeTextInOrder(['Free', 'Your plan', '₱0', 'forever', 'For trying Nexus with a couple of clients.', '2 Stars', '10 Connections', '3,000 tool calls a week'])
        ->assertSeeTextInOrder(['Pro', 'Billed yearly', '₱4,999', '/ year', 'About ₱417 a month. ₱499 if you pay monthly.', 'Unlimited Stars', 'Unlimited Connections', 'Unlimited tool calls'])
        ->assertSeeTextInOrder(['Pay on PayMongo', 'No auto-renew', 'Nothing is deleted']);
});

it('switches Pro\'s price and copy between yearly and monthly', function (): void {
    $this->actingAs(User::factory()->create());

    // After an update Livewire's text assertions read the JSON response, so these read the rendered HTML.
    Livewire::test('pages::billing.upgrade')
        ->set('period', 'month')
        ->assertSeeHtmlInOrder(['Billed monthly', '₱499', '/ month', '₱5,988 a year if you pay monthly. Yearly saves ₱989.'])
        ->assertDontSeeText('₱4,999')
        ->set('period', 'year')
        ->assertSeeHtmlInOrder(['Billed yearly', '₱4,999', '/ year', 'About ₱417 a month. ₱499 if you pay monthly.'])
        ->assertDontSeeText('Billed monthly');
});

it('preselects the period a link asks for, and yearly for anything else', function (string|array $asked, string $picked): void {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['period' => $asked])->test('pages::billing.upgrade')
        ->assertSet('period', $picked);
})->with([
    'monthly' => ['month', 'month'],
    'yearly' => ['year', 'year'],
    'unknown' => ['decade', 'year'],
    'an array' => [['month'], 'year'],
]);

it('prices Pro yearly when the picker sends something else', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::billing.upgrade')
        ->set('period', 'fortnight')
        ->assertOk()
        ->assertSeeHtmlInOrder(['Billed yearly', '₱4,999', '/ year']);
});

it('reads the prices and the Free limits from the plans', function (): void {
    config([
        'nexus.plans.free' => ['stars' => 1, 'connections' => 5, 'tool_calls_per_week' => 1000],
        'nexus.plans.pro.prices' => ['month' => 59900, 'year' => 599900],
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::billing.upgrade')
        ->assertSeeTextInOrder(['Save ₱1,189', '1 Star', '5 Connections', '1,000 tool calls a week', '₱5,999', 'About ₱500 a month. ₱599 if you pay monthly.'])
        ->set('period', 'month')
        ->assertSeeHtmlInOrder(['₱599', '₱7,188 a year if you pay monthly. Yearly saves ₱1,189.']);
});

it('reads "Extend Pro" on Pro, with when Pro would end after paying', function (): void {
    $this->actingAs(User::factory()->create(['pro_until' => CarbonImmutable::parse('2026-10-08 06:00:00', 'UTC')]));

    Livewire::test('pages::billing.upgrade')
        ->assertSeeText('Extend Pro')
        ->assertSeeText('Adds a year to Pro: until Oct 8, 2026 becomes until Oct 8, 2027.')
        ->assertDontSeeText('Go Pro')
        ->assertDontSeeText('Your plan')
        ->set('period', 'month')
        ->assertSeeText('Adds a month to Pro: until Oct 8, 2026 becomes until Nov 8, 2026.');
});

it('adds a month to the last day of a month without running into the next', function (): void {
    $this->actingAs(User::factory()->create(['pro_until' => CarbonImmutable::parse('2027-01-31 06:00:00', 'UTC')]));

    Livewire::withQueryParams(['period' => 'month'])->test('pages::billing.upgrade')
        ->assertSeeText('Adds a month to Pro: until Jan 31, 2027 becomes until Feb 28, 2027.');
});

it('says payments aren\'t set up in place of the payment button', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::billing.upgrade')
        ->assertSeeText('Payments aren\'t set up on this Nexus yet.')
        ->assertDontSeeText('Continue to payment');
});
