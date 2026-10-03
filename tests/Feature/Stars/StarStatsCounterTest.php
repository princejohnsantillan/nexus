<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarStatsCounter;

beforeEach(function (): void {
    $this->travelTo('2026-10-03 12:00:00');

    $this->user = User::factory()->create();
    $this->connection = Connection::factory()->for($this->user)->create();
    $this->star = Star::factory()->for($this->user)->including($this->connection)->create();
    $this->counter = resolve(StarStatsCounter::class);
});

/**
 * Record a call through the Star at this time.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordStarCall(Star $star, Connection $connection, string $at, array $attributes = []): ActivityEntry
{
    return ActivityEntry::factory()->through($star, $connection)->create(['created_at' => $at, ...$attributes]);
}

it('counts the Star\'s calls in the last 24 hours, up to now', function (): void {
    recordStarCall($this->star, $this->connection, '2026-10-02 11:00:00');
    recordStarCall($this->star, $this->connection, '2026-10-02 12:00:00');
    recordStarCall($this->star, $this->connection, '2026-10-02 12:00:01');
    recordStarCall($this->star, $this->connection, '2026-10-03 11:59:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 12:00:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 12:00:01');

    $stats = $this->counter->for($this->star);

    expect($stats->calls)->toBe(3)
        ->and(array_sum($stats->callsByHour))->toBe(3);
});

it('counts each hour\'s calls, oldest first, ending with the hour up to now', function (): void {
    recordStarCall($this->star, $this->connection, '2026-10-02 12:30:00');
    recordStarCall($this->star, $this->connection, '2026-10-02 13:00:00');
    recordStarCall($this->star, $this->connection, '2026-10-02 13:00:01');
    recordStarCall($this->star, $this->connection, '2026-10-03 06:15:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 06:45:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 11:00:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 11:30:00');
    recordStarCall($this->star, $this->connection, '2026-10-03 12:00:00');

    $stats = $this->counter->for($this->star);

    expect($stats->callsByHour)->toBe([2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 1, 2]);
});

it('gives 24 empty hours to a Star without calls', function (): void {
    $stats = $this->counter->for($this->star);

    expect($stats->calls)->toBe(0)
        ->and($stats->errors)->toBe(0)
        ->and($stats->callsByHour)->toBe(array_fill(0, 24, 0))
        ->and($stats->lastCall)->toBeNull();
});

it('counts only this Star\'s calls', function (): void {
    $sibling = Star::factory()->for($this->user)->including($this->connection)->create();
    $someoneElse = User::factory()->create();
    $theirConnection = Connection::factory()->for($someoneElse)->create();
    $theirStar = Star::factory()->for($someoneElse)->including($theirConnection)->create();
    $deleted = Star::factory()->for($this->user)->including($this->connection)->create();
    recordStarCall($deleted, $this->connection, '2026-10-03 11:59:00');
    $deleted->delete();
    recordStarCall($this->star, $this->connection, '2026-10-03 11:00:00', ['client_name' => 'Laptop']);
    recordStarCall($sibling, $this->connection, '2026-10-03 11:58:00', ['status' => ActivityStatus::Error]);
    recordStarCall($theirStar, $theirConnection, '2026-10-03 11:59:00', ['status' => ActivityStatus::Error]);

    $stats = $this->counter->for($this->star);

    expect($stats->calls)->toBe(1)
        ->and($stats->errors)->toBe(0)
        ->and($stats->lastCall?->client_name)->toBe('Laptop');
});

it('counts every call that didn\'t end OK as an error', function (): void {
    foreach (ActivityStatus::cases() as $status) {
        recordStarCall($this->star, $this->connection, '2026-10-03 11:00:00', ['status' => $status]);
    }

    $stats = $this->counter->for($this->star);

    expect($stats->calls)->toBe(5)
        ->and($stats->errors)->toBe(4);
});

it('finds the Star\'s latest call however long ago it was', function (): void {
    recordStarCall($this->star, $this->connection, '2026-09-28 09:00:00', ['client_name' => 'Laptop']);
    $latest = recordStarCall($this->star, $this->connection, '2026-09-30 09:00:00', ['client_name' => 'Desktop']);
    recordStarCall($this->star, $this->connection, '2026-09-29 09:00:00', ['client_name' => 'Phone']);

    $stats = $this->counter->for($this->star);

    expect($stats->calls)->toBe(0)
        ->and($stats->lastCall?->is($latest))->toBeTrue();
});

it('counts the Star\'s tools that are on', function (): void {
    ConnectionTool::factory()->for($this->connection)->create(['read_only' => true]);
    ConnectionTool::factory()->for($this->connection)->create(['read_only' => false]);
    ConnectionTool::factory()->for($this->connection)->create(['read_only' => null]);
    ConnectionTool::factory()->for(Connection::factory()->for($this->user))->create(['read_only' => true]);

    $stats = $this->counter->for($this->star);

    expect($stats->toolsOn)->toBe(1)
        ->and($stats->tools)->toBe(3);
});
