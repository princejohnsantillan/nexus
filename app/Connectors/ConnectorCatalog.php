<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Enums\ClientRegistration;
use App\Exceptions\InvalidConnectorDefinition;
use App\Models\Connection;
use App\Rules\HeaderName;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

/**
 * The gallery's connectors, loaded from a directory (`resources/connectors`
 * in the app) and strictly validated:
 *
 *     $connectors = $catalog->all();          // array<string, Connector>, by name
 *     $github = $catalog->find('github');     // ?Connector
 *
 * Each connector is `{key}.json` plus its official logo at
 * `logos/{key}.svg`. The file name is the connector's key, which is also the
 * handle suggested for its first Connection. Unknown fields are rejected, so
 * a typo or a credential never slips into a definition: deployment OAuth app
 * credentials come from the environment (see Connector::deploymentApp()).
 */
final class ConnectorCatalog
{
    /**
     * Leaves room in a handle for a suffix such as "-12".
     */
    public const int KEY_MAX_LENGTH = Connection::HANDLE_MAX_LENGTH - 4;

    /**
     * The fields a definition may have.
     *
     * @var list<string>
     */
    private const array FIELDS = [
        'name', 'summary', 'url', 'docs_url', 'registration', 'scopes',
        'preview', 'requires_deployment_app', 'app', 'token', 'select_account',
    ];

    /**
     * What a logo may not contain: anything that runs, styles the page,
     * links or loads something else, or declares entities.
     */
    private const string UNSAFE_SVG = '/<(script|style|foreignObject|image|use|iframe|a)\b|\son[a-z]+\s*=|javascript:|href\s*=|url\s*\(|@import|<!|<\?/i';

    /**
     * @var array<string, Connector>|null
     */
    private ?array $connectors = null;

    public function __construct(private readonly string $directory) {}

    /**
     * Every connector, keyed by key and sorted by name.
     *
     * @return array<string, Connector>
     *
     * @throws InvalidConnectorDefinition when a definition or logo is invalid
     */
    public function all(): array
    {
        return $this->connectors ??= $this->load();
    }

    /**
     * @throws InvalidConnectorDefinition when a definition or logo is invalid
     */
    public function find(?string $key): ?Connector
    {
        return $key === null ? null : $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, Connector>
     */
    private function load(): array
    {
        $connectors = [];

        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $connector = $this->parse($file);
            $connectors[$connector->key] = $connector;
        }

        uasort($connectors, fn (Connector $a, Connector $b): int => strcasecmp($a->name, $b->name));

        return $connectors;
    }

    private function parse(string $file): Connector
    {
        $key = basename($file, '.json');
        $fail = fn (string $problem): InvalidConnectorDefinition => new InvalidConnectorDefinition("{$key}.json: {$problem}");

        if (preg_match(Connection::HANDLE_PATTERN, $key) !== 1 || strlen($key) > self::KEY_MAX_LENGTH) {
            throw $fail('the file name is the connector\'s key, so it must be at most '.self::KEY_MAX_LENGTH.' lowercase letters, digits and dashes, starting with a letter.');
        }

        try {
            $data = json_decode(file_get_contents($file) ?: '', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $fail("not valid JSON ({$exception->getMessage()}).");
        }

        if (! is_array($data) || array_is_list($data)) {
            throw $fail('a definition must be a JSON object.');
        }

        $unknown = array_diff(array_keys($data), self::FIELDS);

        if ($unknown !== []) {
            throw $fail('unknown field ['.implode(', ', $unknown).'].');
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:50'],
            'summary' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'docs_url' => ['required', 'string', 'max:2048', 'url:https'],
            'registration' => ['required', Rule::enum(ClientRegistration::class)],
            'scopes' => ['sometimes', 'list'],
            'scopes.*' => ['string', 'distinct', 'regex:/^\S+$/'],
            'preview' => ['sometimes', 'boolean:strict'],
            'requires_deployment_app' => ['sometimes', 'boolean:strict'],
            'select_account' => ['sometimes', 'boolean:strict'],
            'app' => ['required_if:registration,'.ClientRegistration::PreRegistered->value, 'array:console_url,instructions,manifest'],
            'app.console_url' => ['required_with:app', 'string', 'max:2048', 'url:https'],
            'app.instructions' => ['required_with:app', 'string', 'max:1000'],
            'app.manifest' => ['sometimes', 'array'],
            'token' => ['sometimes', 'array:console_url,instructions,header_name,value_prefix'],
            'token.console_url' => ['required_with:token', 'string', 'max:2048', 'url:https'],
            'token.instructions' => ['required_with:token', 'string', 'max:1000'],
            'token.header_name' => ['sometimes', 'string', new HeaderName],
            'token.value_prefix' => ['sometimes', 'string', 'max:50', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);

        if ($validator->fails()) {
            throw $fail(implode(' ', $validator->errors()->all()));
        }

        return new Connector(
            key: $key,
            name: Arr::string($data, 'name'),
            summary: Arr::string($data, 'summary'),
            url: Arr::string($data, 'url'),
            docsUrl: Arr::string($data, 'docs_url'),
            registration: ClientRegistration::from(Arr::string($data, 'registration')),
            logoSvg: $this->logo($key),
            scopes: array_values(array_filter(Arr::array($data, 'scopes', []), is_string(...))),
            preview: Arr::boolean($data, 'preview', false),
            requiresDeploymentApp: Arr::boolean($data, 'requires_deployment_app', false),
            app: isset($data['app']) ? new ConnectorApp(
                consoleUrl: Arr::string($data, 'app.console_url'),
                instructions: Arr::string($data, 'app.instructions'),
                manifest: Arr::has($data, 'app.manifest') ? Arr::array($data, 'app.manifest') : null,
            ) : null,
            token: isset($data['token']) ? new ConnectorToken(
                consoleUrl: Arr::string($data, 'token.console_url'),
                instructions: Arr::string($data, 'token.instructions'),
                headerName: Arr::string($data, 'token.header_name', Connection::DEFAULT_HEADER_NAME),
                valuePrefix: Arr::string($data, 'token.value_prefix', 'Bearer '),
            ) : null,
            selectAccount: Arr::boolean($data, 'select_account', false),
        );
    }

    /**
     * The connector's official logo: one `<svg>` element with a viewBox and
     * no fixed size, so the page can size it, and nothing executable,
     * styled or external, since it is inlined into the page.
     */
    private function logo(string $key): string
    {
        $fail = fn (string $problem): InvalidConnectorDefinition => new InvalidConnectorDefinition("logos/{$key}.svg: {$problem}");

        $svg = is_file($path = "{$this->directory}/logos/{$key}.svg") ? file_get_contents($path) : false;

        if ($svg === false) {
            throw $fail('missing. Every connector needs its service\'s official SVG logo.');
        }

        $svg = trim($svg);

        if (preg_match('/^<svg\b([^>]*)>.*<\/svg>$/s', $svg, $root) !== 1) {
            throw $fail('the file must hold one <svg> element and nothing else.');
        }

        if (preg_match('/\sviewBox\s*=/', $root[1]) !== 1) {
            throw $fail('the <svg> element needs a viewBox, so the logo can be sized.');
        }

        if (preg_match('/\s(width|height|class|style)\s*=/i', $root[1]) === 1) {
            throw $fail('remove width, height, class and style from the <svg> element; Nexus sizes the logo.');
        }

        if (preg_match(self::UNSAFE_SVG, $svg) === 1) {
            throw $fail('a logo can\'t contain scripts, styles, event handlers, links or anything external.');
        }

        return $svg;
    }
}
