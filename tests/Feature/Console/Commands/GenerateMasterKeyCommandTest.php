<?php

declare(strict_types=1);

use App\Encryption\LocalKeyWrapper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('prints a new master key that Nexus accepts', function (): void {
    Artisan::call('nexus:master-key');

    $line = Str::of(Artisan::output())->explode("\n")->first();
    expect($line)->toStartWith('NEXUS_MASTER_KEY=base64:');

    $masterKey = Str::after($line, 'NEXUS_MASTER_KEY=');
    expect(strlen((string) base64_decode(Str::after($masterKey, 'base64:'), true)))->toBe(32)
        ->and(fn (): LocalKeyWrapper => new LocalKeyWrapper($masterKey))->not->toThrow(Throwable::class);
});

it('prints a different key every time', function (): void {
    Artisan::call('nexus:master-key');
    $first = Artisan::output();

    Artisan::call('nexus:master-key');

    expect(Artisan::output())->not->toBe($first);
});

it('writes no files', function (): void {
    $environmentFile = base_path('.env');
    $before = File::exists($environmentFile) ? File::get($environmentFile) : null;

    $this->artisan('nexus:master-key')->assertSuccessful();

    expect(File::exists($environmentFile) ? File::get($environmentFile) : null)->toBe($before);
});
