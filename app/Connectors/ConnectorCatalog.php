<?php

namespace App\Connectors;

use App\Models\Connection;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The connectors users can pick instead of configuring a server by hand.
 *
 * Each is a JSON file in resources/connectors, named after its key
 * (slack.json → "slack"), with an optional official logo at
 * resources/connectors/logos/{key}.svg. Each must point at the service's own, official
 * remote MCP server, and should set its scopes explicitly: without them,
 * Nexus requests every scope a server advertises.
 */
final class ConnectorCatalog
{
    /** @var array<string, Connector>|null */
    protected static ?array $loaded = null;

    protected const FIELDS = [
        'name', 'summary', 'url', 'icon', 'docs_url', 'registration', 'scopes',
        'preview', 'requires_deployment_app', 'app', 'token',
    ];

    /**
     * @return array<string, Connector>
     */
    public static function all(): array
    {
        return self::$loaded ??= self::load(self::directory());
    }

    public static function find(?string $key): ?Connector
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    public static function directory(): string
    {
        return resource_path('connectors');
    }

    /**
     * Where a connector's official logo lives, if it has one.
     */
    public static function logoPath(string $key): string
    {
        return resource_path("connectors/logos/{$key}.svg");
    }

    /**
     * Forget the loaded definitions, e.g. after changing files in a test.
     */
    public static function flush(): void
    {
        self::$loaded = null;
    }

    /**
     * Read and validate every definition in a directory, sorted by name.
     *
     * @return array<string, Connector>
     *
     * @throws InvalidConnectorDefinition
     */
    public static function load(string $directory): array
    {
        $connectors = [];

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $connector = self::parse($file);
            $connectors[$connector->key] = $connector;
        }

        uasort($connectors, fn (Connector $a, Connector $b): int => strcasecmp($a->name, $b->name));

        return $connectors;
    }

    /**
     * @throws InvalidConnectorDefinition
     */
    public static function parse(string $file): Connector
    {
        $key = basename($file, '.json');
        $fail = fn (string $problem): InvalidConnectorDefinition => new InvalidConnectorDefinition('resources/connectors/'.basename($file).": {$problem}");

        if (preg_match(Connection::HANDLE_PATTERN, $key) !== 1) {
            throw $fail('the file name is the connector key, so it must be lowercase letters, digits and dashes.');
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || array_is_list($data)) {
            throw $fail('not a JSON object ('.json_last_error_msg().').');
        }

        if (($unknown = array_diff(array_keys($data), self::FIELDS)) !== []) {
            throw $fail('unknown field ['.implode(', ', $unknown).'].');
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:50'],
            'summary' => ['required', 'string', 'max:300'],
            'url' => ['required', 'url:https'],
            'icon' => ['sometimes', Rule::enum(Heroicon::class)],
            'docs_url' => ['required', 'url:https'],
            'registration' => ['required', Rule::enum(ClientRegistration::class)],
            'scopes' => ['sometimes', 'list'],
            'scopes.*' => ['string', 'distinct'],
            'preview' => ['sometimes', 'boolean'],
            'requires_deployment_app' => ['sometimes', 'boolean'],
            'app' => ['required_if:registration,'.ClientRegistration::PreRegistered->value, 'array:console_url,instructions,manifest'],
            'app.console_url' => ['required_with:app', 'url:https'],
            'app.instructions' => ['required_with:app', 'string'],
            'app.manifest' => ['sometimes', 'array'],
            'token' => ['sometimes', 'array:console_url,instructions,header,prefix'],
            'token.console_url' => ['required_with:token', 'url:https'],
            'token.instructions' => ['required_with:token', 'string'],
            'token.header' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9-]+$/'],
            'token.prefix' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            throw $fail(implode(' ', $validator->errors()->all()));
        }

        return new Connector(
            key: $key,
            name: $data['name'],
            summary: $data['summary'],
            url: $data['url'],
            icon: file_exists(self::logoPath($key)) ? "connector-{$key}" : Heroicon::tryFrom($data['icon'] ?? '') ?? Heroicon::OutlinedPuzzlePiece,
            docsUrl: $data['docs_url'],
            registration: ClientRegistration::from($data['registration']),
            scopes: $data['scopes'] ?? [],
            appConsoleUrl: $data['app']['console_url'] ?? null,
            appInstructions: $data['app']['instructions'] ?? null,
            appManifest: $data['app']['manifest'] ?? null,
            preview: $data['preview'] ?? false,
            requiresDeploymentApp: $data['requires_deployment_app'] ?? false,
            token: isset($data['token']) ? new TokenAuth(
                consoleUrl: $data['token']['console_url'],
                instructions: $data['token']['instructions'],
                header: $data['token']['header'] ?? 'Authorization',
                prefix: $data['token']['prefix'] ?? 'Bearer ',
            ) : null,
        );
    }
}
