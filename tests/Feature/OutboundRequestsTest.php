<?php

declare(strict_types=1);

use App\Exceptions\OutboundRequestBlocked;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

it('sends a request to a public HTTPS host', function (): void {
    Http::fake(['https://mcp.example.com/*' => Http::response('ok')]);

    $response = Http::get('https://mcp.example.com/mcp');

    expect($response->body())->toBe('ok');
});

it('pins the request to the approved addresses and does not stream it', function (): void {
    $this->fakeDns(['mcp.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]);
    $transferOptions = [];
    Http::fake(['https://mcp.example.com/*' => function (Request $request, array $options) use (&$transferOptions) {
        $transferOptions = $options;

        return Http::response('ok');
    }]);

    Http::withOptions(['stream' => true])->get('https://mcp.example.com/mcp');

    expect($transferOptions)
        ->toHaveKey('curl.'.CURLOPT_RESOLVE, ['mcp.example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'])
        ->toHaveKey('stream', false)
        ->toHaveKey('allow_redirects', false);
});

it('connects directly when the environment configures a proxy', function (string $variable): void {
    $transferOptions = [];
    Http::fake(['https://mcp.example.com/*' => function (Request $request, array $options) use (&$transferOptions) {
        $transferOptions = $options;

        return Http::response('ok');
    }]);
    putenv("{$variable}=http://127.0.0.1:18945");

    try {
        Http::get('https://mcp.example.com/mcp');
    } finally {
        putenv($variable);
    }

    expect($transferOptions)->toHaveKey('proxy', '');
})->with(['HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy']);

it('connects directly when the request asks for a proxy', function (array|string $proxy): void {
    $transferOptions = [];
    Http::fake(['https://mcp.example.com/*' => function (Request $request, array $options) use (&$transferOptions) {
        $transferOptions = $options;

        return Http::response('ok');
    }]);

    Http::withOptions(['proxy' => $proxy])->get('https://mcp.example.com/mcp');

    expect($transferOptions)->toHaveKey('proxy', '');
})->with([
    'one proxy' => 'http://127.0.0.1:18945',
    'per scheme' => [['https' => 'http://127.0.0.1:18945']],
]);

it('refuses a request with curl options that connect somewhere else', function (int $option, string|array $value): void {
    Http::fake(['https://mcp.example.com/*' => Http::response('ok')]);

    expect(fn (): Response => Http::withOptions(['curl' => [$option => $value]])->get('https://mcp.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, 'Nexus only connects to the addresses it approved.');

    Http::assertNothingSent();
})->with([
    'connect to' => [CURLOPT_CONNECT_TO, ['mcp.example.com:443:127.0.0.1:443']],
    'unix socket' => [CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock'],
]);

it('blocks a request to a private address before it leaves the app', function (): void {
    $this->fakeDns(['metadata.example.com' => ['169.254.169.254']]);
    Http::fake(['https://metadata.example.com/*' => Http::response('secret')]);

    expect(fn (): Response => Http::get('https://metadata.example.com/latest/meta-data'))
        ->toThrow(OutboundRequestBlocked::class, '[metadata.example.com] resolves to a private or reserved address.');

    Http::assertNothingSent();
});

it('returns a redirect response instead of following it', function (): void {
    Http::fake([
        'https://mcp.example.com/*' => Http::response(status: 302, headers: ['Location' => 'https://elsewhere.example.com/mcp']),
        'https://elsewhere.example.com/*' => Http::response('followed'),
    ]);

    $response = Http::get('https://mcp.example.com/mcp');

    expect($response->status())->toBe(302)
        ->and($response->header('Location'))->toBe('https://elsewhere.example.com/mcp');
    Http::assertSentCount(1);
});

it('refuses a request that turns redirects back on', function (): void {
    Http::fake(['https://mcp.example.com/*' => Http::response('ok')]);

    expect(fn (): Response => Http::withOptions(['allow_redirects' => true])->get('https://mcp.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, 'Nexus does not follow redirects.');

    Http::assertNothingSent();
});

it('honours the switches in the local environment', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config([
        'nexus.outbound.block_private_networks' => false,
        'nexus.outbound.require_https' => false,
    ]);
    $this->fakeDns(['localhost' => ['127.0.0.1']]);
    Http::fake(['http://localhost:8080/*' => Http::response('ok')]);

    $response = Http::get('http://localhost:8080/mcp');

    expect($response->body())->toBe('ok');
});

it('keeps private networks blocked outside the local environment', function (string $environment): void {
    $this->app->detectEnvironment(fn (): string => $environment);
    config(['nexus.outbound.block_private_networks' => false]);
    $this->fakeDns(['localhost' => ['127.0.0.1']]);
    Http::fake(['https://localhost/*' => Http::response('ok')]);

    expect(fn (): Response => Http::get('https://localhost/mcp'))
        ->toThrow(OutboundRequestBlocked::class, '[localhost] resolves to a private or reserved address.');

    Http::assertNothingSent();
})->with(['production', 'staging', 'testing']);

it('keeps requiring HTTPS outside the local environment', function (string $environment): void {
    $this->app->detectEnvironment(fn (): string => $environment);
    config(['nexus.outbound.require_https' => false]);
    Http::fake(['http://mcp.example.com/*' => Http::response('ok')]);

    expect(fn (): Response => Http::get('http://mcp.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, 'Only HTTPS URLs are allowed.');

    Http::assertNothingSent();
})->with(['production', 'staging', 'testing']);
