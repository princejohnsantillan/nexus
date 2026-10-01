<?php

declare(strict_types=1);

use App\Connectors\ConnectorToken;

it('sends a pasted token after the value prefix', function (string $pasted): void {
    $token = new ConnectorToken('https://github.com/settings/personal-access-tokens/new', 'Make one.');

    expect($token->headerValue($pasted))->toBe('Bearer github_pat_123');
})->with([
    'the token alone' => 'github_pat_123',
    'with spaces and a line break around it' => "  github_pat_123\n",
    'with the prefix pasted too' => 'Bearer github_pat_123',
    'with the prefix in another case' => 'bearer  github_pat_123',
]);

it('keeps a token that only starts with the prefix\'s letters', function (): void {
    $token = new ConnectorToken('https://example.com/tokens', 'Make one.');

    expect($token->headerValue('Bearerish-123'))->toBe('Bearer Bearerish-123');
});

it('uses the prefix the connector names, or none', function (string $prefix, string $pasted, string $sent): void {
    $token = new ConnectorToken('https://example.com/tokens', 'Make one.', 'X-API-Key', $prefix);

    expect($token->headerValue($pasted))->toBe($sent);
})->with([
    'no prefix' => ['', 'sk-123', 'sk-123'],
    'a prefix without a space' => ['key=', 'key=sk-123', 'key=sk-123'],
    'another scheme' => ['Token ', 'sk-123', 'Token sk-123'],
]);
