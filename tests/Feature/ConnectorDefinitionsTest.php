<?php

namespace Tests\Feature;

use App\Connectors\ConnectorCatalog;
use App\Connectors\InvalidConnectorDefinition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectorDefinitionsTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/nexus-connectors-'.Str::random(8);
        File::makeDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        ConnectorCatalog::flush();

        parent::tearDown();
    }

    public function test_every_shipped_definition_is_valid(): void
    {
        $files = glob(ConnectorCatalog::directory().'/*.json');

        $this->assertNotEmpty($files);
        $this->assertCount(count($files), ConnectorCatalog::load(ConnectorCatalog::directory()));
    }

    public function test_each_connector_gets_deployment_app_settings_by_convention(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(ConnectorCatalog::all()), array_keys(config('nexus.connectors')));
        $this->assertSame(['client_id', 'client_secret'], array_keys(config('nexus.connectors.slack')));
    }

    public function test_a_minimal_definition_loads_with_defaults(): void
    {
        $this->write('sentry', $this->minimal());

        $sentry = ConnectorCatalog::load($this->directory)['sentry'];

        $this->assertSame('Sentry', $sentry->name);
        $this->assertSame([], $sentry->scopes);
        $this->assertNull($sentry->scope());
        $this->assertFalse($sentry->preview);
        $this->assertTrue($sentry->isAvailable());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>|string, 2: string}>
     */
    public static function invalidDefinitions(): array
    {
        $minimal = self::minimalDefinition();

        return [
            'not JSON' => ['broken', '{"name": ', 'not a JSON object'],
            'missing url' => ['nourl', array_diff_key($minimal, ['url' => true]), 'url field is required'],
            'plain http' => ['insecure', [...$minimal, 'url' => 'http://mcp.example.com/mcp'], 'url'],
            'typo in a field name' => ['typo', [...$minimal, 'scope' => 'read'], 'unknown field [scope]'],
            'unknown icon' => ['badicon', [...$minimal, 'icon' => 'o-not-an-icon'], 'icon'],
            'registered app without instructions' => ['noapp', [...$minimal, 'registration' => 'pre_registered'], 'app field is required'],
            'scopes as a string' => ['scopestring', [...$minimal, 'scopes' => 'read write'], 'scopes'],
            'key that is not a handle' => ['Bad_Name', $minimal, 'file name is the connector key'],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $definition
     */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_fail_with_the_file_and_the_problem(string $key, array|string $definition, string $problem): void
    {
        $this->write($key, $definition);

        try {
            ConnectorCatalog::load($this->directory);
            $this->fail('Expected the definition to be rejected.');
        } catch (InvalidConnectorDefinition $exception) {
            $this->assertStringContainsString("resources/connectors/{$key}.json", $exception->getMessage());
            $this->assertStringContainsString($problem, $exception->getMessage());
        }
    }

    public function test_manifest_placeholders_are_filled_in(): void
    {
        $this->write('acme', [
            ...$this->minimal(),
            'registration' => 'pre_registered',
            'scopes' => ['read', 'write'],
            'app' => [
                'console_url' => 'https://acme.example.com/apps',
                'instructions' => 'Create an app.',
                'manifest' => ['redirects' => ['{{callback_url}}'], 'scopes' => '{{scopes}}', 'name' => 'Nexus'],
            ],
        ]);

        $manifest = json_decode((string) ConnectorCatalog::load($this->directory)['acme']->appManifestJson('https://nexus.example/oauth/callback'), true);

        $this->assertSame(['redirects' => ['https://nexus.example/oauth/callback'], 'scopes' => ['read', 'write'], 'name' => 'Nexus'], $manifest);
    }

    /**
     * @return array<string, mixed>
     */
    protected function minimal(): array
    {
        return self::minimalDefinition();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function minimalDefinition(): array
    {
        return [
            'name' => 'Sentry',
            'summary' => 'Issues and errors.',
            'url' => 'https://mcp.sentry.dev/mcp',
            'icon' => 'o-bug-ant',
            'docs_url' => 'https://docs.sentry.io/product/sentry-mcp/',
            'registration' => 'automatic',
        ];
    }

    /**
     * @param  array<string, mixed>|string  $definition
     */
    protected function write(string $key, array|string $definition): void
    {
        File::put("{$this->directory}/{$key}.json", is_string($definition) ? $definition : json_encode($definition));
    }
}
