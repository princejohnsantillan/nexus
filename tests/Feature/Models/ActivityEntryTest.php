<?php

declare(strict_types=1);

use App\Models\ActivityEntry;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('prunes entries older than the 30-day retention period and keeps the rest', function (): void {
    $this->travelTo('2026-10-02 12:00:00');
    $expired = ActivityEntry::factory()->create(['created_at' => '2026-09-02 11:59:59']);
    $kept = ActivityEntry::factory()->create(['created_at' => '2026-09-02 12:00:00']);
    $recent = ActivityEntry::factory()->create(['created_at' => '2026-10-02 11:00:00']);

    $this->artisan('model:prune', ['--model' => [ActivityEntry::class]])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
    $this->assertModelExists($recent);
});

it('keeps entries for the configured number of days', function (): void {
    config(['nexus.activity.retention_days' => 7]);
    $this->travelTo('2026-10-02 12:00:00');
    $expired = ActivityEntry::factory()->create(['created_at' => '2026-09-25 11:59:59']);
    $kept = ActivityEntry::factory()->create(['created_at' => '2026-09-25 12:00:00']);

    $this->artisan('model:prune', ['--model' => [ActivityEntry::class]])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
});

it('prunes activity once a day', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('0 0 * * *')
        ->and($events->sole()->command)->toContain(ActivityEntry::class);
});
