<?php

declare(strict_types=1);

use App\Encryption\DataKeys;
use App\Encryption\KeyWrapper;
use App\Encryption\SecretCipher;
use App\Encryption\Secrets;
use App\Models\User;
use Illuminate\Support\Str;
use Pest\Expectation;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\Fixtures\SecretHolder;

beforeEach(function (): void {
    SecretHolder::createTable();
});

/**
 * Each object that holds a secret, with the secret it must never reveal.
 */
dataset('secret holders', [
    'secrets after reading them' => function (): array {
        $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
        $holder->secrets->put(['access_token' => 'sk-live-123']);
        $holder->save();
        $secrets = SecretHolder::query()->sole()->secrets;
        $secrets->get('access_token');

        return [$secrets, 'sk-live-123'];
    },
    'secrets with unsaved changes' => function (): array {
        $secrets = new Secrets;
        $secrets->put(['access_token' => 'sk-live-123']);

        return [$secrets, 'sk-live-123'];
    },
    'data keys' => function (): array {
        $user = User::factory()->create();

        return [resolve(DataKeys::class), resolve(DataKeys::class)->forUser($user->id)];
    },
    'the secret cipher' => function (): array {
        $user = User::factory()->create();

        return [resolve(SecretCipher::class), resolve(DataKeys::class)->forUser($user->id)];
    },
    'the key wrapper' => function (): array {
        $masterKey = base64_decode(Str::after(config()->string('nexus.encryption.master_key'), 'base64:'));

        return [resolve(KeyWrapper::class), $masterKey];
    },
]);

it('refuses to serialize an object holding secrets', function (array $holderAndSecret): void {
    [$holder] = $holderAndSecret;

    expect(fn (): string => serialize($holder))
        ->toThrow(LogicException::class, $holder::class.' holds secrets, so it cannot be serialized.');
})->with('secret holders');

it('refuses to unserialize an object that holds secrets', function (array $holderAndSecret): void {
    [$holder] = $holderAndSecret;
    $class = $holder::class;

    expect(fn (): mixed => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'))
        ->toThrow(LogicException::class, $class.' holds secrets, so it cannot be unserialized.');
})->with('secret holders');

it('redacts the secret from var_dump, print_r and dump', function (array $holderAndSecret): void {
    [$holder, $secret] = $holderAndSecret;
    ob_start();
    var_dump($holder);
    $varDump = (string) ob_get_clean();

    $dumps = [
        'var_dump' => $varDump,
        'print_r' => print_r($holder, true),
        'dump' => (string) (new CliDumper)->dump((new VarCloner)->cloneVar($holder), true),
    ];

    expect($dumps)->each(fn (Expectation $output): Expectation => $output
        ->toContain('[redacted]')
        ->not->toContain($secret)
        ->not->toContain(base64_encode($secret)));
})->with('secret holders');
