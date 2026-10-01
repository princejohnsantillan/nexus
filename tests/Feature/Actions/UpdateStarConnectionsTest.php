<?php

declare(strict_types=1);

use App\Actions\UpdateStarConnections;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('includes only the Star owner\'s own Connections', function (): void {
    $user = User::factory()->create();
    $own = Connection::factory()->for($user)->create();
    $someoneElses = Connection::factory()->create();
    $star = Star::factory()->for($user)->create();

    resolve(UpdateStarConnections::class)->handle($star, [$own->id, $someoneElses->id]);

    expect($star->connections()->pluck('connections.id')->all())->toBe([$own->id]);
});

it('changes nothing for a deleted Star', function (): void {
    $user = User::factory()->create();
    $connection = Connection::factory()->for($user)->create();
    $star = Star::factory()->for($user)->create();
    Star::query()->whereKey($star->id)->delete();

    expect(fn () => resolve(UpdateStarConnections::class)->handle($star, [$connection->id]))
        ->toThrow(ModelNotFoundException::class);
});
