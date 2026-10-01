<?php

declare(strict_types=1);

use App\Encryption\LocalKeyWrapper;
use App\Exceptions\MasterKeyException;
use Illuminate\Contracts\Encryption\DecryptException;
use Tests\Support\Tamper;

it('unwraps a data key for the user it was wrapped for', function (): void {
    $wrapper = new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)));
    $dataKey = random_bytes(32);

    $wrapped = $wrapper->wrap($dataKey, 7);

    expect($wrapper->unwrap($wrapped, 7))->toBe($dataKey)
        ->and($wrapped)->not->toContain(base64_encode($dataKey));
});

it('accepts the master key with or without the base64: prefix', function (): void {
    $masterKey = base64_encode(random_bytes(32));
    $dataKey = random_bytes(32);
    $wrapped = new LocalKeyWrapper('base64:'.$masterKey)->wrap($dataKey, 7);

    expect(new LocalKeyWrapper($masterKey)->unwrap($wrapped, 7))->toBe($dataKey);
});

it('refuses to unwrap a data key for another user', function (): void {
    $wrapper = new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)));
    $wrapped = $wrapper->wrap(random_bytes(32), 7);

    $wrapper->unwrap($wrapped, 8);
})->throws(DecryptException::class, 'The data key does not belong to this user.');

it('refuses to unwrap a data key that is not 32 bytes', function (): void {
    $wrapper = new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)));
    $wrapped = $wrapper->wrap(random_bytes(16), 7);

    $wrapper->unwrap($wrapped, 7);
})->throws(DecryptException::class, 'The data key is corrupt.');

it('refuses to unwrap a data key wrapped by another master key', function (): void {
    $wrapped = new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)))->wrap(random_bytes(32), 7);

    new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)))->unwrap($wrapped, 7);
})->throws(DecryptException::class);

it('refuses to unwrap a tampered data key', function (): void {
    $wrapper = new LocalKeyWrapper('base64:'.base64_encode(random_bytes(32)));
    $wrapped = $wrapper->wrap(random_bytes(32), 7);

    $wrapper->unwrap(Tamper::flipBit($wrapped), 7);
})->throws(DecryptException::class);

it('refuses to start without a master key', function (mixed $masterKey): void {
    new LocalKeyWrapper($masterKey);
})->with([
    'not set' => null,
    'empty' => '',
])->throws(MasterKeyException::class, 'NEXUS_MASTER_KEY is not set');

it('refuses to start with an invalid master key without revealing it', function (mixed $masterKey): void {
    expect(fn (): LocalKeyWrapper => new LocalKeyWrapper($masterKey))->toThrow(function (MasterKeyException $exception) use ($masterKey): void {
        expect($exception->getMessage())
            ->toStartWith('NEXUS_MASTER_KEY is not a valid master key')
            ->not->toContain((string) $masterKey);
    });
})->with([
    'not base64' => 'base64:not*valid*base64*at*all*here!',
    'too short' => 'base64:'.base64_encode(str_repeat('k', 16)),
    'too long' => 'base64:'.base64_encode(str_repeat('k', 33)),
    '32 characters, not base64-encoded' => str_repeat('k', 32),
    'not a string' => true,
]);
