<?php

declare(strict_types=1);

use App\Models\Connection;
use Illuminate\Support\Facades\DB;

it('refuses to change the handle once the Connection is created', function (): void {
    $connection = Connection::factory()->create(['handle' => 'deepwiki']);

    expect(fn (): bool => $connection->forceFill(['handle' => 'renamed'])->save())
        ->toThrow(LogicException::class, 'A Connection\'s handle never changes once it is created.');

    expect(DB::table('connections')->value('handle'))->toBe('deepwiki');
});

it('stores the header value encrypted and never serializes it', function (): void {
    $connection = Connection::factory()->withHeader('Bearer sk-live-123')->create();

    expect(DB::table('connections')->value('secrets'))->toBeString()->not->toContain('sk-live-123')
        ->and($connection->toArray())->not->toHaveKey('secrets')
        ->and($connection->toJson())->not->toContain('sk-live-123')
        ->and(Connection::query()->sole()->headerValue())->toBe('Bearer sk-live-123');
});

it('never mass-assigns secrets', function (): void {
    $connection = Connection::factory()->withHeader('Bearer sk-live-123')->create();

    $connection->fill(['secrets' => null])->save();

    expect(Connection::query()->sole()->headerValue())->toBe('Bearer sk-live-123');
});
