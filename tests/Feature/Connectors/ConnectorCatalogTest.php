<?php

declare(strict_types=1);

use App\Connectors\ConnectorCatalog;
use App\Enums\ClientRegistration;
use App\Enums\SignInMethod;
use App\Exceptions\InvalidConnectorDefinition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const TEST_CONNECTOR_LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M0 0h24v24H0z"/></svg>';

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/nexus-connectors-'.Str::random(12);
    File::makeDirectory($this->directory.'/logos', recursive: true);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

/**
 * A definition with only the required fields.
 *
 * @return array<string, mixed>
 */
function minimalDefinition(): array
{
    return [
        'name' => 'Sentry',
        'summary' => 'Issues and releases.',
        'url' => 'https://mcp.sentry.dev/mcp',
        'docs_url' => 'https://docs.sentry.io/product/sentry-mcp/',
        'registration' => 'automatic',
    ];
}

/**
 * Write a connector's JSON file (an array, or raw text) and, unless told not to, its logo.
 *
 * @param  array<string, mixed>|string  $definition
 */
function writeConnector(string $directory, string $key, array|string $definition, ?string $logo = TEST_CONNECTOR_LOGO): void
{
    File::put("{$directory}/{$key}.json", is_string($definition) ? $definition : json_encode($definition, JSON_UNESCAPED_SLASHES));

    if ($logo !== null) {
        File::put("{$directory}/logos/{$key}.svg", $logo);
    }
}

it('validates every shipped connector and offers GitHub, Linear and Notion by name', function (): void {
    $catalog = new ConnectorCatalog(resource_path('connectors'));

    expect(array_keys($catalog->all()))->toBe(['github', 'linear', 'notion'])
        ->and(count($catalog->all()))->toBe(count(File::glob(resource_path('connectors/*.json'))));
});

it('ships GitHub with a token method and its pre-registered OAuth app\'s scopes', function (): void {
    $github = app(ConnectorCatalog::class)->find('github');

    expect($github->url)->toBe('https://api.githubcopilot.com/mcp/')
        ->and($github->registration)->toBe(ClientRegistration::PreRegistered)
        ->and($github->scopes)->toBe(['repo', 'read:org', 'read:user', 'user:email'])
        ->and($github->app?->consoleUrl)->toBe('https://github.com/settings/applications/new')
        ->and($github->token?->consoleUrl)->toBe('https://github.com/settings/personal-access-tokens/new')
        ->and($github->token?->instructions)->toContain('fine-grained')
        ->and($github->token?->headerName)->toBe('Authorization')
        ->and($github->token?->valuePrefix)->toBe('Bearer ');
});

it('ships Notion and Linear with automatic client registration and no token method', function (string $key, string $url): void {
    $connector = app(ConnectorCatalog::class)->find($key);

    expect($connector->url)->toBe($url)
        ->and($connector->registration)->toBe(ClientRegistration::Automatic)
        ->and($connector->token)->toBeNull()
        ->and($connector->methods())->toBe([SignInMethod::OAuth]);
})->with([
    'Notion' => ['notion', 'https://mcp.notion.com/mcp'],
    'Linear' => ['linear', 'https://mcp.linear.app/mcp'],
]);

it('inlines each shipped logo with nothing executable or external, GitHub\'s and Linear\'s in the text colour', function (): void {
    foreach (app(ConnectorCatalog::class)->all() as $key => $connector) {
        expect($connector->logo('size-6')->toHtml())->toStartWith('<svg aria-hidden="true" focusable="false" class="size-6" ')
            ->and(File::get(resource_path("connectors/logos/{$key}.svg")))->not->toMatch('/<script|<style|<foreignObject|<image|\son[a-z]+\s*=|javascript:|href\s*=/i');
    }

    expect(app(ConnectorCatalog::class)->find('github')->logoSvg)->toContain('fill="currentColor"')
        ->and(app(ConnectorCatalog::class)->find('linear')->logoSvg)->toContain('fill="currentColor"');
});

it('reads each shipped connector\'s deployment OAuth app from its own environment variables', function (): void {
    $catalog = new ConnectorCatalog(resource_path('connectors'));

    expect(array_keys(config()->array('nexus.connectors')))->toEqualCanonicalizing(array_keys($catalog->all()))
        ->and(config()->array('nexus.connectors.github'))->toBe(['client_id' => null, 'client_secret' => null])
        ->and($catalog->find('github')->environmentVariable('CLIENT_ID'))->toBe('NEXUS_GITHUB_CLIENT_ID')
        ->and(File::get(base_path('.env.example')))->toContain("NEXUS_GITHUB_CLIENT_ID=\nNEXUS_GITHUB_CLIENT_SECRET=");
});

it('names environment variables after the key, with underscores for dashes', function (): void {
    writeConnector($this->directory, 'google-drive', [...minimalDefinition(), 'name' => 'Google Drive']);

    expect(new ConnectorCatalog($this->directory)->find('google-drive')->environmentVariable('CLIENT_SECRET'))->toBe('NEXUS_GOOGLE_DRIVE_CLIENT_SECRET');
});

it('uses the deployment\'s OAuth app when one is configured, so users need none of their own', function (): void {
    $github = app(ConnectorCatalog::class)->find('github');

    expect($github->deploymentApp())->toBeNull()
        ->and($github->needsUserApp())->toBeTrue();

    config(['nexus.connectors.github' => ['client_id' => 'Iv1.abc', 'client_secret' => 'shh']]);

    expect($github->deploymentApp())->toBe(['client_id' => 'Iv1.abc', 'client_secret' => 'shh'])
        ->and($github->needsUserApp())->toBeFalse();
});

it('loads a minimal definition with defaults', function (): void {
    writeConnector($this->directory, 'sentry', minimalDefinition());

    $sentry = new ConnectorCatalog($this->directory)->find('sentry');

    expect($sentry->name)->toBe('Sentry')
        ->and($sentry->scopes)->toBe([])
        ->and($sentry->preview)->toBeFalse()
        ->and($sentry->requiresDeploymentApp)->toBeFalse()
        ->and($sentry->app)->toBeNull()
        ->and($sentry->token)->toBeNull()
        ->and($sentry->logoSvg)->toBe(TEST_CONNECTOR_LOGO);
});

it('reads a token method\'s header name and value prefix', function (): void {
    writeConnector($this->directory, 'sentry', [...minimalDefinition(), 'token' => [
        'console_url' => 'https://sentry.io/settings/account/api/auth-tokens/',
        'instructions' => 'Create a user auth token.',
        'header_name' => 'X-Sentry-Token',
        'value_prefix' => '',
    ]]);

    $token = new ConnectorCatalog($this->directory)->find('sentry')->token;

    expect($token?->headerName)->toBe('X-Sentry-Token')
        ->and($token?->headerValue('sntrys_123'))->toBe('sntrys_123');
});

it('refuses an invalid definition, naming the file and the problem', function (string $key, array|string $definition, string $problem): void {
    writeConnector($this->directory, $key, $definition);

    expect(fn (): array => new ConnectorCatalog($this->directory)->all())
        ->toThrow(InvalidConnectorDefinition::class, "{$key}.json: {$problem}");
})->with([
    'not JSON' => ['broken', '{"name": ', 'not valid JSON'],
    'a list' => ['listed', '["GitHub"]', 'a definition must be a JSON object.'],
    'missing a URL' => ['nourl', array_diff_key(minimalDefinition(), ['url' => true]), 'The url field is required.'],
    'plain HTTP' => ['insecure', [...minimalDefinition(), 'url' => 'http://mcp.example.com/mcp'], 'The url field must be a valid URL.'],
    'a typo in a field name' => ['typo', [...minimalDefinition(), 'scope' => ['read']], 'unknown field [scope].'],
    'a client ID' => ['secretive', [...minimalDefinition(), 'client_id' => 'Iv1.abc', 'client_secret' => 'shh'], 'unknown field [client_id, client_secret].'],
    'an unknown token field' => ['tokenfield', [...minimalDefinition(), 'token' => ['console_url' => 'https://example.com/tokens', 'instructions' => 'Make one.', 'secret' => 'shh']], 'The token field must be an array.'],
    'an unknown registration' => ['register', [...minimalDefinition(), 'registration' => 'manual'], 'The selected registration is invalid.'],
    'a registered app without instructions' => ['noapp', [...minimalDefinition(), 'registration' => 'pre_registered'], 'The app field is required when registration is pre_registered.'],
    'scopes as one string' => ['scopestring', [...minimalDefinition(), 'scopes' => 'read write'], 'The scopes field must be a list.'],
    'a scope with a space' => ['scopespace', [...minimalDefinition(), 'scopes' => ['read write']], 'The scopes.0 field format is invalid.'],
    'preview as a string' => ['previewstring', [...minimalDefinition(), 'preview' => 'yes'], 'The preview field must be true or false.'],
    'a token without instructions' => ['tokennoinstr', [...minimalDefinition(), 'token' => ['console_url' => 'https://example.com/tokens']], 'The token.instructions field is required when token is present.'],
    'a token header with a space' => ['tokenheader', [...minimalDefinition(), 'token' => ['console_url' => 'https://example.com/tokens', 'instructions' => 'Make one.', 'header_name' => 'X Api Key']], 'Enter a header name such as Authorization or X-API-Key'],
    'a file name that isn\'t a handle' => ['Bad_Name', minimalDefinition(), 'the file name is the connector\'s key'],
    'a file name too long for a handle' => [str_repeat('a', 21), minimalDefinition(), 'the file name is the connector\'s key'],
]);

it('refuses a connector without a safe official logo', function (?string $logo, string $problem): void {
    writeConnector($this->directory, 'sentry', minimalDefinition(), $logo);

    expect(fn (): array => new ConnectorCatalog($this->directory)->all())
        ->toThrow(InvalidConnectorDefinition::class, "logos/sentry.svg: {$problem}");
})->with([
    'no logo' => [null, 'missing.'],
    'not an SVG' => ['<p>Sentry</p>', 'the file must hold one <svg> element and nothing else.'],
    'an XML prolog' => ['<?xml version="1.0"?>'.TEST_CONNECTOR_LOGO, 'the file must hold one <svg> element and nothing else.'],
    'no viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h24v24H0z"/></svg>', 'the <svg> element needs a viewBox'],
    'a fixed size' => ['<svg viewBox="0 0 24 24" width="24" height="24"><path d="M0 0h24v24H0z"/></svg>', 'remove width, height, class and style'],
    'a script' => ['<svg viewBox="0 0 24 24"><script>alert(1)</script></svg>', 'a logo can\'t contain scripts'],
    'an event handler' => ['<svg viewBox="0 0 24 24"><path onclick="alert(1)" d="M0 0h24v24H0z"/></svg>', 'a logo can\'t contain scripts'],
    'an external image' => ['<svg viewBox="0 0 24 24"><image href="https://example.com/logo.png"/></svg>', 'a logo can\'t contain scripts'],
    'a style' => ['<svg viewBox="0 0 24 24"><style>path{fill:red}</style><path d="M0 0h24v24H0z"/></svg>', 'a logo can\'t contain scripts'],
]);

it('offers a token method whenever the connector has one, but no OAuth sign-in yet', function (): void {
    $github = app(ConnectorCatalog::class)->find('github');

    expect($github->methods())->toBe([SignInMethod::Token, SignInMethod::OAuth])
        ->and($github->availableMethods())->toBe([SignInMethod::Token])
        ->and($github->suggestedMethod())->toBe(SignInMethod::Token)
        ->and($github->whyUnavailable(SignInMethod::OAuth))->toBe('Nexus can\'t sign in with OAuth yet.')
        ->and($github->isAvailable())->toBeTrue()
        ->and($github->unavailableReason())->toBeNull();
});

it('explains why a connector that only signs in with OAuth isn\'t available', function (): void {
    $notion = app(ConnectorCatalog::class)->find('notion');

    expect($notion->isAvailable())->toBeFalse()
        ->and($notion->availableMethods())->toBe([])
        ->and($notion->suggestedMethod())->toBeNull()
        ->and($notion->unavailableReason())->toBe('Notion only supports signing in with OAuth. Nexus can\'t sign in with OAuth yet.');
});

it('explains which variables a connector that needs the deployment\'s own OAuth app is missing', function (): void {
    writeConnector($this->directory, 'gmail', [...minimalDefinition(), 'name' => 'Gmail', 'requires_deployment_app' => true]);

    expect(new ConnectorCatalog($this->directory)->find('gmail')->unavailableReason())
        ->toBe('Gmail only supports signing in with OAuth. This Nexus has no Gmail OAuth app yet. Its operator needs to register one and set NEXUS_GMAIL_CLIENT_ID and NEXUS_GMAIL_CLIENT_SECRET.');
});
