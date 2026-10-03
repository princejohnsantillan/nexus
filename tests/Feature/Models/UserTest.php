<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

it('stores the end of Pro after paying as the instant one Philippine month later', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-04-01 00:00:00', 'UTC'));
    $user = User::factory()->create(['pro_until' => CarbonImmutable::parse('2027-04-30 18:00:00', 'UTC')]);

    $user->forceFill(['pro_until' => $user->proUntilAfterPaying(BillingPeriod::Month)])->save();

    expect($user->fresh()?->pro_until?->toIso8601String())->toBe('2027-05-31T18:00:00+00:00');
});

it('counts a payment from now when Pro has ended, and from its end while it lasts', function (?string $proUntil, string $expected): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
    $user = User::factory()->create(['pro_until' => $proUntil === null ? null : CarbonImmutable::parse($proUntil, 'UTC')]);

    expect($user->proUntilAfterPaying(BillingPeriod::Year)->toIso8601String())->toBe($expected);
})->with([
    'never Pro' => [null, '2027-10-03T06:00:00+00:00'],
    'Pro ended' => ['2026-09-01 00:00:00', '2027-10-03T06:00:00+00:00'],
    'Pro lasting' => ['2026-12-01 00:00:00', '2027-12-01T00:00:00+00:00'],
]);
