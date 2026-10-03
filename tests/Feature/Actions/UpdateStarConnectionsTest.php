<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Actions\UpdateStarConnections;
use App\Models\Connection;
use App\Models\ConnectionTool;
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

it('adds one Connection, keeping the Star\'s others and their switches', function (): void {
    $user = User::factory()->create();
    $kept = Connection::factory()->for($user)->create();
    ConnectionTool::factory()->for($kept)->create(['name' => 'write']);
    $added = Connection::factory()->for($user)->create();
    $star = Star::factory()->for($user)->including($kept)->create();
    resolve(SwitchStarTools::class)->handle($star, $kept, true);

    expect(resolve(UpdateStarConnections::class)->add($star, $added))->toBeTrue();

    expect($star->connections()->pluck('connections.id')->all())->toEqualCanonicalizing([$kept->id, $added->id])
        ->and($star->toolSwitches()->pluck('tool_name')->all())->toBe(['write']);
});

it('adds nothing when the Star already includes the Connection', function (): void {
    $user = User::factory()->create();
    $connection = Connection::factory()->for($user)->create();
    ConnectionTool::factory()->for($connection)->create(['name' => 'write']);
    $star = Star::factory()->for($user)->including($connection)->create();
    resolve(SwitchStarTools::class)->handle($star, $connection, true);

    expect(resolve(UpdateStarConnections::class)->add($star, $connection))->toBeFalse();

    expect($star->connections()->pluck('connections.id')->all())->toBe([$connection->id])
        ->and($star->toolSwitches()->pluck('tool_name')->all())->toBe(['write']);
});

it('refuses to add another user\'s Connection', function (): void {
    $user = User::factory()->create();
    $own = Connection::factory()->for($user)->create();
    $someoneElses = Connection::factory()->create();
    $star = Star::factory()->for($user)->including($own)->create();

    expect(resolve(UpdateStarConnections::class)->add($star, $someoneElses))->toBeFalse();

    expect($star->connections()->pluck('connections.id')->all())->toBe([$own->id]);
});

it('adds nothing to a deleted Star', function (): void {
    $user = User::factory()->create();
    $connection = Connection::factory()->for($user)->create();
    $star = Star::factory()->for($user)->create();
    Star::query()->whereKey($star->id)->delete();

    expect(fn () => resolve(UpdateStarConnections::class)->add($star, $connection))
        ->toThrow(ModelNotFoundException::class);
});
