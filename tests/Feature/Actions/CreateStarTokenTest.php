<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Models\Star;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

it('keeps to the limit when two creations for one Star overlap', function (): void {
    Sleep::fake(syncWithCarbon: true);
    config(['nexus.limits.tokens_per_star' => 1]);
    $star = Star::factory()->create();
    $overlapped = false;
    $refused = null;
    DB::listen(function (QueryExecuted $query) use ($star, &$overlapped, &$refused): void {
        if ($overlapped || ! str_contains($query->sql, 'count(*)') || ! str_contains($query->sql, '"star_tokens"')) {
            return;
        }

        $overlapped = true;

        try {
            resolve(CreateStarToken::class)->handle($star, 'Second');
        } catch (ValidationException $exception) {
            $refused = $exception->errors();
        }
    });

    resolve(CreateStarToken::class)->handle($star, 'First');

    expect($star->tokens()->pluck('name')->all())->toBe(['First'])
        ->and($refused)->toBe(['limit' => ['Another token is being created for this Star. Try again in a moment.']]);
});

it('releases the lock when the Star is at the limit', function (): void {
    config(['nexus.limits.tokens_per_star' => 0]);
    $star = Star::factory()->create();

    expect(fn (): mixed => resolve(CreateStarToken::class)->handle($star, 'Laptop'))
        ->toThrow(ValidationException::class);

    expect(Cache::lock("stars.{$star->id}.new-token", 10)->get())->toBeTrue();
});
