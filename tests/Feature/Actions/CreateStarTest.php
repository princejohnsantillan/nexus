<?php

declare(strict_types=1);

use App\Actions\CreateStar;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

it('creates a Star including only the user\'s own Connections', function (): void {
    $user = User::factory()->create();
    $own = Connection::factory()->for($user)->create();
    $someoneElses = Connection::factory()->create();

    $star = resolve(CreateStar::class)->handle($user, ['name' => 'Work', 'description' => null], [$own->id, $someoneElses->id]);

    expect($star->connections()->pluck('connections.id')->all())->toBe([$own->id]);
});

it('keeps to the limit when two creations for one user overlap', function (): void {
    Sleep::fake(syncWithCarbon: true);
    config(['nexus.limits.stars_per_user' => 1]);
    $user = User::factory()->create();
    $overlapped = false;
    $refused = null;
    DB::listen(function (QueryExecuted $query) use ($user, &$overlapped, &$refused): void {
        if ($overlapped || ! str_contains($query->sql, 'count(*)') || ! str_contains($query->sql, '"stars"')) {
            return;
        }

        $overlapped = true;

        try {
            resolve(CreateStar::class)->handle($user, ['name' => 'Second', 'description' => null]);
        } catch (ValidationException $exception) {
            $refused = $exception->errors();
        }
    });

    resolve(CreateStar::class)->handle($user, ['name' => 'First', 'description' => null]);

    expect($user->stars()->pluck('name')->all())->toBe(['First'])
        ->and($refused)->toBe(['limit' => ['Another Star is being created in your account. Try again in a moment.']]);
});

it('releases the lock when the user is at the limit', function (): void {
    config(['nexus.limits.stars_per_user' => 0]);
    $user = User::factory()->create();

    expect(fn (): mixed => resolve(CreateStar::class)->handle($user, ['name' => 'Work', 'description' => null]))
        ->toThrow(ValidationException::class);

    expect(Cache::lock("users.{$user->id}.new-star", 10)->get())->toBeTrue();
});
