<?php

declare(strict_types=1);

use App\Actions\SaveNewConnection;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

it('saves a new Connection for the user while they are below the limit', function (): void {
    $user = User::factory()->create();

    resolve(SaveNewConnection::class)->handle($user, Connection::factory()->for($user)->make(['handle' => 'deepwiki']));

    expect($user->connections()->pluck('handle')->all())->toBe(['deepwiki']);
});

it('keeps to the limit when two saves for one user overlap', function (): void {
    Sleep::fake(syncWithCarbon: true);
    config(['nexus.limits.connections_per_user' => 1]);
    $user = User::factory()->create();
    $overlapped = false;
    $refused = null;
    DB::listen(function (QueryExecuted $query) use ($user, &$overlapped, &$refused): void {
        if ($overlapped || ! str_contains($query->sql, 'count(*)')) {
            return;
        }

        $overlapped = true;

        try {
            resolve(SaveNewConnection::class)->handle($user, Connection::factory()->for($user)->make(['handle' => 'second']));
        } catch (ValidationException $exception) {
            $refused = $exception->errors();
        }
    });

    resolve(SaveNewConnection::class)->handle($user, Connection::factory()->for($user)->make(['handle' => 'first']));

    expect($user->connections()->pluck('handle')->all())->toBe(['first'])
        ->and($refused)->toBe(['limit' => ['Another Connection is being added to your account. Try again in a moment.']]);
});
