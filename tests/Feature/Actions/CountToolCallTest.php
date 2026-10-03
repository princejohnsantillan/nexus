<?php

declare(strict_types=1);

use App\Actions\CountToolCall;
use App\Exceptions\WeeklyToolCallLimitReached;
use App\Models\ToolCallCount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('lets only one of two calls racing for the last one through', function (int $limit, int $alreadyMade): void {
    config(['nexus.plans.free.tool_calls_per_week' => $limit]);
    $user = User::factory()->create();

    if ($alreadyMade > 0) {
        ToolCallCount::factory()->for($user)->create(['calls' => $alreadyMade]);
    }

    $refusals = 0;
    $count = function () use ($user, &$refusals): void {
        try {
            resolve(CountToolCall::class)->handle($user);
        } catch (WeeklyToolCallLimitReached) {
            $refusals++;
        }
    };
    $overlapped = false;
    DB::listen(function (QueryExecuted $query) use ($count, &$overlapped): void {
        if ($overlapped || ! str_contains($query->sql, 'tool_call_counts')) {
            return;
        }

        $overlapped = true;
        $count();
    });

    $count();

    expect($refusals)->toBe(1)
        ->and($user->toolCallCounts()->sole()->only(['week_starts_on', 'calls']))->toBe(['week_starts_on' => '2026-09-28', 'calls' => $limit]);
})->with([
    'the week\'s first call, with a limit of 1' => [1, 0],
    'the second call, with a limit of 2' => [2, 1],
]);

it('says how many calls the plan includes and when they reset', function (): void {
    config(['nexus.plans.free.tool_calls_per_week' => 2]);
    $user = User::factory()->create();
    ToolCallCount::factory()->for($user)->create(['calls' => 2]);

    expect(fn (): mixed => resolve(CountToolCall::class)->handle($user))->toThrow(
        WeeklyToolCallLimitReached::class,
        'This Nexus account has used its 2 free tool calls this week. They reset on Monday, Oct 5 at 12:00 AM Philippine time.',
    );
});

it('refuses every call, and counts none, when the plan allows none', function (): void {
    config(['nexus.plans.free.tool_calls_per_week' => 0]);
    $user = User::factory()->create();

    expect(fn (): mixed => resolve(CountToolCall::class)->handle($user))->toThrow(WeeklyToolCallLimitReached::class)
        ->and($user->toolCallCounts()->exists())->toBeFalse();
});
