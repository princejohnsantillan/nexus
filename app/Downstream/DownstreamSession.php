<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Exceptions\DownstreamRequestFailed;
use Closure;
use InvalidArgumentException;
use Laravel\Mcp\Client\Exceptions\TransportException;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use stdClass;
use Throwable;

/**
 * One conversation with a Connection's MCP server. It connects on the first
 * request and reuses that connection for the rest.
 *
 * Tools and results come back as the exact JSON text the server sent, and
 * tool arguments go out as the exact JSON text given, so nothing is lost to
 * decoding and encoding: `{}` stays `{}` and long numbers keep every digit.
 * Every failure throws DownstreamRequestFailed, whose message never contains
 * text from the server.
 */
final readonly class DownstreamSession
{
    /**
     * How many pages of tools Nexus reads before deciding the server never stops.
     */
    private const int MAX_TOOL_PAGES = 100;

    public function __construct(
        private DownstreamMcpClient $client,
        private DownstreamTransport $transport,
    ) {}

    /**
     * Every tool the server lists, as the JSON object it sent for each,
     * following its cursors page by page. Each tool appears once, the last
     * one listed under its name; entries without a name are skipped.
     *
     * @return list<string>
     *
     * @throws DownstreamRequestFailed
     */
    public function listTools(): array
    {
        $this->connect();

        return $this->attempt(function (): array {
            $tools = [];
            $cursor = null;
            $seenCursors = [];

            for ($page = 1; ; $page++) {
                $this->client->send(new RawRequest('tools/list', $cursor === null ? [] : ['cursor' => $cursor]));

                $result = $this->transport->takeLastResult() ?? throw DownstreamRequestFailed::protocolError();
                $listed = RawJson::elements(RawJson::member($result, 'tools') ?? '') ?? throw DownstreamRequestFailed::protocolError();

                foreach ($listed as $tool) {
                    $decoded = json_decode($tool);

                    if ($decoded instanceof stdClass && is_string($decoded->name ?? null)) {
                        $tools[$decoded->name] = $tool;
                    }
                }

                $cursor = json_decode(RawJson::member($result, 'nextCursor') ?? 'null');

                if (! is_string($cursor) || $cursor === '') {
                    return array_values($tools);
                }

                if (isset($seenCursors[$cursor]) || $page === self::MAX_TOOL_PAGES) {
                    throw DownstreamRequestFailed::protocolError();
                }

                $seenCursors[$cursor] = true;
            }
        });
    }

    /**
     * Call a tool with its arguments, a JSON object sent exactly as given, and
     * return the server's result exactly as it sent it, including a result
     * that reports a tool error with `isError`. A JSON-RPC error instead of a
     * result is a tool error.
     *
     * @throws InvalidArgumentException when the arguments aren't a JSON object
     * @throws DownstreamRequestFailed
     */
    public function callTool(string $name, string $arguments): string
    {
        if (! RawJson::isObject($arguments)) {
            throw new InvalidArgumentException('Tool arguments must be a JSON object.');
        }

        $this->connect();

        return $this->attempt(fn (): string => $this->transport->sendingToolArguments($arguments, function () use ($name): string {
            $this->client->send(new RawRequest('tools/call', ['name' => $name, 'arguments' => new stdClass]));

            return $this->transport->takeLastResult() ?? throw DownstreamRequestFailed::protocolError();
        }), isToolCall: true);
    }

    /**
     * Connect, unless already connected: `server/discover` where the server
     * speaks 2026-07-28, else the `initialize` handshake.
     *
     * @throws DownstreamRequestFailed
     */
    private function connect(): void
    {
        $this->attempt(fn (): DownstreamMcpClient => $this->client->connect());
    }

    /**
     * Run a request, turning laravel/mcp's exceptions into DownstreamRequestFailed.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $request
     * @return TResult
     *
     * @throws DownstreamRequestFailed
     */
    private function attempt(Closure $request, bool $isToolCall = false): mixed
    {
        try {
            return $request();
        } catch (DownstreamRequestFailed $failed) {
            throw $failed;
        } catch (ClientException|JsonRpcException $exception) {
            throw $this->classify($exception, $isToolCall);
        }
    }

    /**
     * Why a request failed. The protocol may wrap the exception that says
     * why, e.g. when the fallback handshake fails too, so the whole chain is
     * searched: first for a failure the transport or protocol already
     * classified, then for a JSON-RPC error or an HTTP rejection.
     */
    private function classify(Throwable $exception, bool $isToolCall): DownstreamRequestFailed
    {
        $chain = [];

        for ($cause = $exception; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof DownstreamRequestFailed) {
                return $cause;
            }

            $chain[] = $cause;
        }

        foreach ($chain as $cause) {
            if ($cause instanceof JsonRpcException) {
                return $isToolCall
                    ? DownstreamRequestFailed::toolError($cause->getCode())
                    : DownstreamRequestFailed::jsonRpcError($cause->getCode());
            }

            if ($cause instanceof TransportException && $cause->getCode() >= 400 && $cause->getCode() < 500) {
                return DownstreamRequestFailed::rejected($cause->getCode());
            }
        }

        return DownstreamRequestFailed::protocolError();
    }
}
