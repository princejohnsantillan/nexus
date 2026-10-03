<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\ProReminder;
use App\Models\User;
use App\Notifications\ProReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config(['nexus.plans.free.stars' => 2, 'nexus.plans.free.connections' => 10, 'nexus.plans.free.tool_calls_per_week' => 3000]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
});

/**
 * The Pro emails the user was sent, oldest first: which one, and the
 * `pro_until` (UTC) it was for.
 *
 * @return list<array{ProReminder, string}>
 */
function sentProReminders(User $user): array
{
    return Notification::sent($user, ProReminderNotification::class)
        ->map(fn (ProReminderNotification $email): array => [$email->reminder, $email->proUntil->toDateTimeString()])
        ->values()
        ->all();
}

/**
 * An email's text as its reader sees it, without tags or extra whitespace.
 */
function emailText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

it('emails each user the reminder their Pro\'s end date is due', function (?string $proUntil, array $expected): void {
    $user = User::factory()->create(['pro_until' => $proUntil]);
    Notification::fake();

    $this->artisan('nexus:billing:remind')->assertSuccessful();

    expect(sentProReminders($user))->toBe($expected);
})->with([
    'never had Pro' => [null, []],
    'Pro with a year left' => ['2027-10-03 12:00:00', []],
    'Pro ends in 8 days' => ['2026-10-11 12:00:00', []],
    'Pro ends a second after 7 days from now' => ['2026-10-10 12:00:01', []],
    'Pro ends exactly 7 days from now' => ['2026-10-10 12:00:00', [[ProReminder::EndsSoon, '2026-10-10 12:00:00']]],
    'Pro ends in 5 days' => ['2026-10-08 12:00:00', [[ProReminder::EndsSoon, '2026-10-08 12:00:00']]],
    'Pro ends in a minute' => ['2026-10-03 12:01:00', [[ProReminder::EndsSoon, '2026-10-03 12:01:00']]],
    'Pro ends right now' => ['2026-10-03 12:00:00', [[ProReminder::Ended, '2026-10-03 12:00:00']]],
    'Pro ended yesterday' => ['2026-10-02 12:00:00', [[ProReminder::Ended, '2026-10-02 12:00:00']]],
    'Pro ended a second under 48 hours ago' => ['2026-10-01 12:00:01', [[ProReminder::Ended, '2026-10-01 12:00:01']]],
    'Pro ended exactly 48 hours ago' => ['2026-10-01 12:00:00', []],
    'Pro ended 10 days ago' => ['2026-09-23 12:00:00', []],
]);

it('sends each reminder once per end date, however often it runs', function (): void {
    $endingSoon = User::factory()->proEndingIn(5)->create();
    $ended = User::factory()->proEnded()->create();
    Notification::fake();

    $this->artisan('nexus:billing:remind')
        ->expectsOutputToContain('Queued "Pro ends soon" for 1 user.')
        ->expectsOutputToContain('Queued "Pro has ended" for 1 user.')
        ->assertSuccessful();
    $this->artisan('nexus:billing:remind')
        ->expectsOutputToContain('Queued "Pro ends soon" for 0 users.')
        ->assertSuccessful();
    $this->travel(1)->day();
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    expect(sentProReminders($endingSoon))->toBe([[ProReminder::EndsSoon, '2026-10-08 12:00:00']])
        ->and(sentProReminders($ended))->toBe([[ProReminder::Ended, '2026-10-02 12:00:00']]);
});

it('starts over for the new end date each time Pro is extended', function (): void {
    $user = User::factory()->proEndingIn(5)->create();
    Notification::fake();

    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $user->forceFill(['pro_until' => $user->proUntilAfterPaying(BillingPeriod::Month)])->save();
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $user->forceFill(['pro_until' => $user->proUntilAfterPaying(BillingPeriod::Year)])->save();
    $this->travelTo(CarbonImmutable::parse('2027-11-05 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    expect(sentProReminders($user))->toBe([
        [ProReminder::EndsSoon, '2026-10-08 12:00:00'],
        [ProReminder::EndsSoon, '2026-11-08 12:00:00'],
        [ProReminder::Ended, '2026-11-08 12:00:00'],
        [ProReminder::EndsSoon, '2027-11-09 12:00:00'],
    ]);
});

it('waits for the new end date when Pro is extended before the reminder went', function (): void {
    $user = User::factory()->proEndingIn(10)->create();
    $user->forceFill(['pro_until' => $user->proUntilAfterPaying(BillingPeriod::Month)])->save();
    Notification::fake();

    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    expect(sentProReminders($user))->toBe([[ProReminder::EndsSoon, '2026-11-13 12:00:00']]);
});

it('skips users without an email address, and emails them once they add one', function (): void {
    $user = User::factory()->withHiddenEmail()->proEndingIn(5)->create();
    Notification::fake();

    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $skipped = sentProReminders($user);
    $user->update(['email' => 'ada@example.com']);
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    expect($skipped)->toBe([])
        ->and(sentProReminders($user))->toBe([[ProReminder::EndsSoon, '2026-10-08 12:00:00']]);
});

it('lists who would be emailed on a dry run, and sends and records nothing', function (): void {
    $endingSoon = User::factory()->proEndingIn(5)->create(['email' => 'ada@example.com']);
    $ended = User::factory()->proEnded()->create(['email' => 'grace@example.com']);
    Notification::fake();

    $this->artisan('nexus:billing:remind', ['--dry-run' => true])
        ->expectsOutputToContain('Would email "Pro ends soon" to 1 user (dry run, nothing sent).')
        ->expectsTable(['User', 'Email', 'Pro until (Asia/Manila)'], [[$endingSoon->id, 'ada@example.com', 'Oct 8, 2026 8:00 PM']])
        ->expectsOutputToContain('Would email "Pro has ended" to 1 user (dry run, nothing sent).')
        ->expectsTable(['User', 'Email', 'Pro until (Asia/Manila)'], [[$ended->id, 'grace@example.com', 'Oct 2, 2026 8:00 PM']])
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($endingSoon->fresh()?->pro_ends_soon_emailed_for)->toBeNull()
        ->and($ended->fresh()?->pro_ended_emailed_for)->toBeNull();
});

it('tells the user the day Pro ends in Philippine time, what Free means and how to extend', function (): void {
    $user = User::factory()->create(['pro_until' => '2026-10-08 17:00:00']);
    Notification::fake();

    $this->artisan('nexus:billing:remind')->assertSuccessful();

    $mail = Notification::sent($user, ProReminderNotification::class)->sole()->toMail($user);
    $html = (string) $mail->render();
    expect($mail->subject)->toBe('Your Nexus Pro ends on Oct 9, 2026')
        ->and(emailText($html))->toContain(
            'Your Pro ends on Oct 9, 2026',
            'Yours ends on Oct 9, 2026. Extend it before then',
            'If it ends, you\'re back on Free: 2 Stars and 10 Connections. Nothing is deleted',
            '3,000 tool calls a week across your Stars.',
            'Extend Pro',
        )
        ->and($html)->toContain('href="'.route('billing.upgrade').'" class="button button-primary"');
});

it('tells the user Pro has ended and they are back on Free, with a way to extend', function (): void {
    $user = User::factory()->create(['pro_until' => '2026-10-02 17:00:00']);
    Notification::fake();

    $this->artisan('nexus:billing:remind')->assertSuccessful();

    $mail = Notification::sent($user, ProReminderNotification::class)->sole()->toMail($user);
    $html = (string) $mail->render();
    expect($mail->subject)->toBe('Your Nexus Pro has ended')
        ->and(emailText($html))->toContain(
            'You\'re back on Free',
            'Your Nexus Pro ended on Oct 3, 2026, so your account is on Free now: 2 Stars and 10 Connections. Nothing is deleted',
            '3,000 tool calls a week across your Stars.',
            'Extend Pro',
        )
        ->and($html)->toContain('href="'.route('billing.upgrade').'" class="button button-primary"');
});

it('sends the queued email from the queue', function (): void {
    config(['queue.default' => 'database']);
    User::factory()->proEndingIn(5)->create();
    Event::fake([NotificationSent::class]);
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    $this->artisan('queue:work', ['--once' => true, '--memory' => 2048])->assertSuccessful();

    Event::assertDispatched(NotificationSent::class, fn (NotificationSent $sent): bool => $sent->notification instanceof ProReminderNotification);
    $this->assertDatabaseEmpty('jobs');
});

it('drops a queued email once Pro has been extended', function (): void {
    config(['queue.default' => 'database']);
    $user = User::factory()->proEndingIn(5)->create();
    Event::fake([NotificationSent::class]);
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $user->forceFill(['pro_until' => $user->proUntilAfterPaying(BillingPeriod::Month)])->save();

    $this->artisan('queue:work', ['--once' => true, '--memory' => 2048])->assertSuccessful();

    Event::assertNotDispatched(NotificationSent::class);
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('failed_jobs');
});

it('sends a queued email late only while it is still due', function (string $proUntil, string $workedAt, array $expected): void {
    config(['queue.default' => 'database']);
    User::factory()->create(['pro_until' => CarbonImmutable::parse($proUntil, 'UTC')]);
    Event::fake([NotificationSent::class]);
    $this->artisan('nexus:billing:remind')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse($workedAt, 'UTC'));
    $this->artisan('nexus:billing:remind')->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 2048])->assertSuccessful();

    expect(Event::dispatched(NotificationSent::class)
        ->map(fn (array $arguments): array => [$arguments[0]->notification->reminder, $arguments[0]->notification->proUntil->toDateTimeString()])
        ->values()
        ->all())->toBe($expected);
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('failed_jobs');
})->with([
    '"ends soon" a day late, before Pro ends' => ['2026-10-08 12:00:00', '2026-10-04 12:00:00', [[ProReminder::EndsSoon, '2026-10-08 12:00:00']]],
    '"ends soon" once Pro has ended' => ['2026-10-03 12:01:00', '2026-10-03 12:02:00', [[ProReminder::Ended, '2026-10-03 12:01:00']]],
    '"has ended" more than 48 hours after Pro ended' => ['2026-10-03 11:00:00', '2026-10-05 12:00:00', []],
]);

it('runs every hour, on one server', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'nexus:billing:remind'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('0 * * * *')
        ->and($events->sole()->onOneServer)->toBeTrue();
});
