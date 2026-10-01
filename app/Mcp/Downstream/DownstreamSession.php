<?php

namespace App\Mcp\Downstream;

use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\WebClient;
use stdClass;

/**
 * One open conversation with a downstream MCP server.
 */
class DownstreamSession
{
    public function __construct(
        protected WebClient $client,
        protected RecordingHttpTransport $transport,
    ) {}

    public function client(): WebClient
    {
        return $this->client;
    }

    /**
     * Every tool the server advertises, exactly as it sent them.
     *
     * @return list<stdClass>
     */
    public function listTools(): array
    {
        $this->transport->takeReceived();

        // Let the client paginate and validate; it drops tools it can't call.
        $accepted = $this->client->tools()->keys()->flip();

        $tools = [];

        foreach ($this->rawResults() as $result) {
            foreach (is_array($result->tools ?? null) ? $result->tools : [] as $tool) {
                if ($tool instanceof stdClass && is_string($tool->name ?? null) && $accepted->has($tool->name)) {
                    $tools[$tool->name] = $tool;
                }
            }
        }

        return array_values($tools);
    }

    /**
     * Call a tool and return the server's result object unchanged.
     *
     * @param  array<string, mixed>  $arguments  Top-level arguments; nested values may be stdClass.
     */
    public function callTool(stdClass $definition, array $arguments): stdClass
    {
        $tool = Tool::from($this->client, json_decode((string) json_encode($definition), true));

        $this->transport->takeReceived();

        $this->client->callTool($tool, $arguments);

        $results = $this->rawResults();

        return end($results) ?: new stdClass;
    }

    /**
     * @return list<stdClass>
     */
    protected function rawResults(): array
    {
        $results = [];

        foreach ($this->transport->takeReceived() as $message) {
            $decoded = json_decode($message, false);

            if ($decoded instanceof stdClass && ($decoded->result ?? null) instanceof stdClass) {
                $results[] = $decoded->result;
            }
        }

        return $results;
    }
}
