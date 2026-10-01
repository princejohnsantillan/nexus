<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Downstream\RawJson;
use App\Models\Star;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;

/**
 * An MCP client for tests, talking to a Star's endpoint through the HTTP
 * kernel, as a 2026-07-28 client by default:
 *
 *     $response = StarClient::for($star)->withToken($token)->callTool('wiki__search', '{"q":"x"}');
 *
 * - speaking('2025-11-25') makes it a client of the older era: it connects
 *   with `initialize` and sends no protocol `_meta`. A 2026-07-28 client
 *   connects with `server/discover`, puts the protocol version and its
 *   capabilities in every request's `_meta`, and mirrors the protocol
 *   version, method and name in the MCP-Protocol-Version, Mcp-Method and
 *   Mcp-Name headers, as laravel/mcp requires.
 * - send() makes any request; params given as a string are sent exactly as
 *   written, so `{}` stays `{}`.
 */
final class StarClient
{
    private ?string $token = null;

    private string $protocolVersion = '2026-07-28';

    private int $nextId = 1;

    /**
     * @var array<string, string>
     */
    private array $extraHeaders = [];

    private function __construct(private readonly Star $star) {}

    public static function for(Star $star): self
    {
        return new self($star);
    }

    public function withToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Send this header with every request too, in place of the one the client would send.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->extraHeaders[$name] = $value;

        return $this;
    }

    /**
     * @param  string  $protocolVersion  '2026-07-28' or '2025-11-25'.
     */
    public function speaking(string $protocolVersion): self
    {
        $this->protocolVersion = $protocolVersion;

        return $this;
    }

    /**
     * `server/discover` for a 2026-07-28 client, `initialize` for an older one.
     */
    public function connect(): TestResponse
    {
        return $this->isModern()
            ? $this->send('server/discover')
            : $this->send('initialize', ['protocolVersion' => $this->protocolVersion, 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'tests', 'version' => '1.0.0']]);
    }

    public function listTools(): TestResponse
    {
        return $this->send('tools/list');
    }

    /**
     * @param  string|null  $arguments  The arguments' JSON, sent as written, or null to send none.
     */
    public function callTool(string $name, ?string $arguments = '{}'): TestResponse
    {
        $params = '{"name":'.json_encode($name).($arguments === null ? '' : ',"arguments":'.$arguments).'}';

        return $this->send('tools/call', $params);
    }

    /**
     * Send one JSON-RPC request.
     *
     * @param  array<string, mixed>|string  $params  The params, or their JSON object.
     */
    public function send(string $method, array|string $params = []): TestResponse
    {
        $params = is_string($params) ? $params : (json_encode((object) $params) ?: '{}');
        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];

        if ($this->isModern()) {
            $params = RawJson::put($params, '_meta', (string) json_encode([
                'io.modelcontextprotocol/protocolVersion' => $this->protocolVersion,
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass,
            ])) ?? throw new InvalidArgumentException('The params must be a JSON object.');

            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
            $headers['Mcp-Method'] = $method;

            $name = json_decode(RawJson::member($params, 'name') ?? 'null');

            if (is_string($name)) {
                $headers['Mcp-Name'] = $name;
            }
        } elseif ($method !== 'initialize') {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $body = '{"jsonrpc":"2.0","id":'.$this->nextId++.',"method":'.json_encode($method).',"params":'.$params.'}';

        return test()->call('POST', route('mcp.star', $this->star), server: $this->serverVariables([...$headers, ...$this->extraHeaders]), content: $body);
    }

    /**
     * The headers as the server variables a request carries them in.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverVariables(array $headers): array
    {
        $variables = [];

        foreach ($headers as $name => $value) {
            $name = strtr(strtoupper($name), '-', '_');
            $variables[$name === 'CONTENT_TYPE' ? $name : 'HTTP_'.$name] = $value;
        }

        return $variables;
    }

    private function isModern(): bool
    {
        return $this->protocolVersion === '2026-07-28';
    }
}
