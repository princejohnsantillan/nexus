<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\Payment;
use App\Models\Star;
use App\Models\ToolCallCount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['nexus.plans.free.stars' => 2, 'nexus.plans.free.connections' => 10, 'nexus.plans.free.tool_calls_per_week' => 3000]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

/**
 * Each usage meter's text, with whether it is at its limit.
 *
 * @return list<array{text: string, atLimit: bool}>
 */
function usageMeters(string $html): array
{
    $meters = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('[data-usage-meter]');

    return array_values(array_map(fn (Element $meter): array => [
        'text' => trim((string) preg_replace('/\s+/', ' ', $meter->textContent ?? '')),
        'atLimit' => $meter->hasAttribute('data-at-limit'),
    ], iterator_to_array($meters)));
}

it('shows Free with what it costs, its usage against its limits, and a way to upgrade', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->count(2)->create();
    Connection::factory()->for($user)->count(7)->create();
    ToolCallCount::factory()->for($user)->create(['calls' => 1240]);

    $page = Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Billing', 'Current plan', 'Free', '₱0 / month', 'Upgrade to Pro'])
        ->assertSeeHtml('href="'.route('billing.upgrade').'"')
        ->assertSeeText('Free forever. Tool calls reset every Monday. Stars and Connections over a limit keep working; you just can\'t add more.')
        ->assertDontSeeText('Extend Pro');

    expect(usageMeters($page->html()))->toBe([
        ['text' => 'Stars 2 of 2 · limit reached', 'atLimit' => true],
        ['text' => 'Connections 7 of 10', 'atLimit' => false],
        ['text' => 'Tool calls this week 1,240 of 3,000', 'atLimit' => false],
    ]);
});

it('shows a Free account over its limits as at them, without hiding anything', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->count(3)->create();
    Connection::factory()->for($user)->count(12)->create();
    ToolCallCount::factory()->for($user)->create(['calls' => 4860]);

    $page = Livewire::actingAs($user)->test('pages::billing.index');

    expect(usageMeters($page->html()))->toBe([
        ['text' => 'Stars 3 of 2 · limit reached', 'atLimit' => true],
        ['text' => 'Connections 12 of 10 · limit reached', 'atLimit' => true],
        ['text' => 'Tool calls this week 4,860 of 3,000 · limit reached', 'atLimit' => true],
    ]);
});

it('counts only the user\'s own Stars, Connections and tool calls', function (): void {
    $user = User::factory()->create();
    Star::factory()->for($user)->create();
    Star::factory()->count(4)->create();
    Connection::factory()->count(9)->create();
    ToolCallCount::factory()->create(['calls' => 3000]);

    $page = Livewire::actingAs($user)->test('pages::billing.index');

    expect(usageMeters($page->html()))->toBe([
        ['text' => 'Stars 1 of 2', 'atLimit' => false],
        ['text' => 'Connections 0 of 10', 'atLimit' => false],
        ['text' => 'Tool calls this week 0 of 3,000', 'atLimit' => false],
    ]);
});

it('shows Pro as active until its end date in Philippine time, with the days left and no limits', function (): void {
    $user = User::factory()->pro()->create();
    Star::factory()->for($user)->count(5)->create();
    Connection::factory()->for($user)->count(14)->create();
    ToolCallCount::factory()->for($user)->create(['calls' => 4860]);

    $page = Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Current plan', 'Pro', 'Active', 'Pro until Oct 3, 2027 · 365 days left', 'Extend Pro'])
        ->assertSeeHtml('href="'.route('billing.upgrade').'"')
        ->assertSeeText('Pro doesn\'t renew on its own. We\'ll email you a week before it ends, and extending adds to the time you have left.')
        ->assertDontSeeText('Upgrade to Pro')
        ->assertDontSeeText('Ends in');

    expect(usageMeters($page->html()))->toBe([
        ['text' => 'Stars 5 of unlimited No limit on Pro', 'atLimit' => false],
        ['text' => 'Connections 14 of unlimited No limit on Pro', 'atLimit' => false],
        ['text' => 'Tool calls this week 4,860 of unlimited No limit on Pro', 'atLimit' => false],
    ]);
});

it('warns in Pro\'s last 7 days and makes extending the main action', function (): void {
    $user = User::factory()->proEndingIn(5)->create();

    Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Current plan', 'Pro', 'Ends in 5 days', 'Pro until Oct 8, 2026 · then you\'re back on Free', 'Extend Pro'])
        ->assertSeeHtml('href="'.route('billing.upgrade').'"')
        ->assertSeeText('Extend before Oct 8 to keep unlimited Stars and Connections. If Pro ends nothing is deleted; you just can\'t add more past the Free limits.')
        ->assertDontSeeText('Active')
        ->assertDontSeeText('days left');
});

it('starts warning 7 days before Pro ends, not 8', function (int $days, bool $warns): void {
    $user = User::factory()->proEndingIn($days)->create();

    $page = Livewire::actingAs($user)->test('pages::billing.index');

    $warns ? $page->assertSeeText("Ends in {$days} days") : $page->assertSeeText("{$days} days left")->assertDontSeeText('Ends in');
})->with([
    '7 days left' => [7, true],
    '8 days left' => [8, false],
]);

it('counts this week\'s tool calls, starting afresh on Monday at midnight in Philippine time', function (): void {
    $user = User::factory()->create();
    ToolCallCount::factory()->for($user)->create(['week_starts_on' => '2026-09-21', 'calls' => 2999]);
    ToolCallCount::factory()->for($user)->create(['week_starts_on' => '2026-09-28', 'calls' => 12]);

    $this->travelTo(CarbonImmutable::parse('2026-10-04 15:59:59', 'UTC'));
    $sunday = usageMeters(Livewire::actingAs($user)->test('pages::billing.index')->html())[2]['text'];

    $this->travelTo(CarbonImmutable::parse('2026-10-04 16:00:00', 'UTC'));
    $monday = usageMeters(Livewire::actingAs($user)->test('pages::billing.index')->html())[2]['text'];

    expect([$sunday, $monday])->toBe(['Tool calls this week 12 of 3,000', 'Tool calls this week 0 of 3,000']);
});

it('shows the date Pro ends on the Philippine calendar', function (): void {
    $user = User::factory()->create(['pro_until' => CarbonImmutable::parse('2026-10-20 18:00:00', 'UTC')]);

    Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeText('Pro until Oct 21, 2026 · 18 days left');
});

it('shows Free again once Pro has ended', function (): void {
    $user = User::factory()->proEnded()->create();

    Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Current plan', 'Free', '₱0 / month', 'Upgrade to Pro'])
        ->assertDontSeeText('Pro until');
});

it('lists no payments yet', function (string $state, string $explanation): void {
    $factory = User::factory();
    $user = ($state === 'pro' ? $factory->pro() : $factory)->create();

    Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Payments', 'Every payment you\'ve made. PayMongo emails you a receipt for each one.', 'No payments yet', $explanation]);
})->with([
    'on Free' => ['free', 'You\'re on Free, so there\'s nothing to pay. Receipts show up here once you go Pro.'],
    'on Pro' => ['pro', 'Receipts show up here once you pay for Pro.'],
]);

/**
 * Each row of the Payments table, as its cells' text.
 *
 * @return list<list<string>>
 */
function paymentRows(string $html): array
{
    $rows = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('[data-payment-row]');

    return array_values(array_map(fn (Element $row): array => array_values(array_map(
        fn (Element $cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->textContent ?? '')),
        iterator_to_array($row->querySelectorAll('td')),
    )), iterator_to_array($rows)));
}

it('lists the user\'s paid payments, newest first, and says how Pro was last paid for', function (): void {
    $user = User::factory()->pro()->create();
    Payment::factory()->for($user)->monthly()->paidByCard('visa', '4242', CarbonImmutable::parse('2026-09-03 02:00:00', 'UTC'))->create();
    Payment::factory()->for($user)->paid('gcash', CarbonImmutable::parse('2026-10-02 17:00:00', 'UTC'))->create();
    Payment::factory()->for($user)->create();
    Payment::factory()->for($user)->monthly()->expired()->create();
    Payment::factory()->paid()->create();

    $page = Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Current plan', 'Pro', 'Paid yearly', 'Active'])
        ->assertSeeTextInOrder(['Payments', 'Date', 'Description', 'Method', 'Amount', 'Status'])
        ->assertDontSeeText('No payments yet');

    expect(paymentRows($page->html()))->toBe([
        ['Oct 3, 2026', 'Nexus Pro · Yearly Oct 3, 2026 · GCash', 'GCash', '₱4,999.00', 'Paid'],
        ['Sep 3, 2026', 'Nexus Pro · Monthly Sep 3, 2026 · Visa ···· 4242', 'Visa ···· 4242', '₱499.00', 'Paid'],
    ]);
});

it('says Pro was paid monthly when the latest payment was for a month', function (): void {
    $user = User::factory()->pro()->create();
    Payment::factory()->for($user)->paid('gcash', CarbonImmutable::parse('2026-08-03 06:00:00', 'UTC'))->create();
    Payment::factory()->for($user)->monthly()->paid('qrph', CarbonImmutable::parse('2026-10-01 06:00:00', 'UTC'))->create();

    Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Pro', 'Paid monthly', 'Active'])
        ->assertDontSeeText('Paid yearly')
        ->assertSeeText('QR Ph');
});

it('lists past payments on Free too, without saying how a plan is paid for', function (): void {
    $user = User::factory()->proEnded()->create();
    Payment::factory()->for($user)->monthly()->paid('paymaya', CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC'))->create();

    $page = Livewire::actingAs($user)->test('pages::billing.index')
        ->assertSeeTextInOrder(['Current plan', 'Free', '₱0 / month'])
        ->assertDontSeeText('Paid monthly');

    expect(paymentRows($page->html()))->toBe([
        ['Sep 1, 2026', 'Nexus Pro · Monthly Sep 1, 2026 · Maya', 'Maya', '₱499.00', 'Paid'],
    ]);
});

it('is linked from the profile menu, above Settings', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSeeInOrder([route('billing.index'), 'Billing', route('settings.index'), 'Settings'], escape: false);

    $this->get(route('billing.index'))
        ->assertOk()
        ->assertSee('<title>Billing · Nexus</title>', escape: false);
});

it('sends guests to sign in', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('auth.sign-in'));
})->with(['billing.index', 'billing.upgrade']);
