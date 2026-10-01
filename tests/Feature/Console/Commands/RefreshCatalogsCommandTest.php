<?php

declare(strict_types=1);

use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

it('queues a background refresh of every Connection\'s catalog', function (): void {
    $connections = collect([
        ...Connection::factory()->count(2)->create(),
        Connection::factory()->oauth()->create(),
    ]);
    Queue::fake([RefreshCatalogInBackground::class]);

    $this->artisan('nexus:refresh-catalogs')
        ->expectsOutputToContain('Queued a catalog refresh for each of 3 Connections.')
        ->assertSuccessful();

    expect(Queue::pushed(RefreshCatalogInBackground::class)->map(fn (RefreshCatalogInBackground $job): int => $job->connectionId)->sort()->values()->all())
        ->toBe($connections->pluck('id')->sort()->values()->all());
});

it('runs every day, on one server', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'nexus:refresh-catalogs'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('0 0 * * *')
        ->and($events->sole()->onOneServer)->toBeTrue();
});
