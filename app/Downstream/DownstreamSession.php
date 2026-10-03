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
 * Tools, prompts and results come back as the exact JSON text the server
 * sent, and the arguments of tool calls and prompts go out as the exact JSON
 * text given, so nothing is lost to decoding and encoding: `{}` stays `{}`
 * and long numbers keep every digit.
 * Every failure throws DownstreamRequestFailed, whose message never contains
 * text from the server.
 */
final readonly class DownstreamSession
{
    /**
     * How many pages of tools or prompts Nexus reads before deciding the server never stops.
     */
    private const int MAX_PAGES = 100;

    /**
     * @param  (Closure(): string)|null  $accessToken  An OAuth Connection's access token for the server, renewed first when it has expired; null when the session signs in some other way, or not at all.
     */
    public function __construct(
        private DownstreamMcpClient $client,
        private DownstreamTransport $transport,
        private ?Closure $accessToken = null,
    ) {}

    /**
     * Make sure the session can sign in to its server, without sending the
     * server anything: an OAuth Connection's access token is read, and renewed
     * first when it has expired or is about to, as before any request. A
     * session that signs in some other way, or not at all, has nothing to check.
     *
     * @throws DownstreamRequestFailed as needing sign-in when the Connection isn't signed in or its sign-in can't be renewed, or as the renewal failed
     */
    public function signIn(): void
    {
        if ($this->accessToken instanceof Closure) {
            ($this->accessToken)();
        }
    }

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
        return $this->listEvery('tools/list', 'tools');
    }

    /**
     * Whether the server may have tools: it declared the `tools` capability
     * when the session connected, or declared no capabilities at all, so a
     * server that leaves them out is still asked. A server that declares
     * others without `tools`, such as one with only prompts, has none, and
     * may refuse `tools/list` outright.
     *
     * @throws DownstreamRequestFailed
     */
    public function offersTools(): bool
    {
        $this->connect();

        $capabilities = $this->client->capabilities();

        return $capabilities === [] || array_key_exists('tools', $capabilities);
    }

    /**
     * Whether the server said it has prompts (the `prompts` capability) when
     * the session connected.
     *
     * @throws DownstreamRequestFailed
     */
    public function offersPrompts(): bool
    {
        $this->connect();

        return array_key_exists('prompts', $this->client->capabilities());
    }

    /**
     * Every prompt the server lists, as the JSON object it sent for each,
     * following its cursors page by page. Each prompt appears once, the last
     * one listed under its name; entries without a name are skipped.
     *
     * @return list<string>
     *
     * @throws DownstreamRequestFailed
     */
    public function listPrompts(): array
    {
        return $this->listEvery('prompts/list', 'prompts');
    }

    /**
     * Get a prompt with its arguments, a JSON object sent exactly as given,
     * and return the server's result (its messages) exactly as it sent it.
     *
     * @throws InvalidArgumentException when the arguments aren't a JSON object
     * @throws DownstreamRequestFailed
     */
    public function getPrompt(string $name, string $arguments): string
    {
        if (! RawJson::isObject($arguments)) {
            throw new InvalidArgumentException('Prompt arguments must be a JSON object.');
        }

        $this->connect();

        return $this->attempt(fn (): string => $this->transport->sendingArguments($arguments, function () use ($name): string {
            $this->client->send(new RawRequest('prompts/get', ['name' => $name, 'arguments' => new stdClass]));

            return $this->transport->takeResult() ?? throw DownstreamRequestFailed::protocolError();
        }));
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

        return $this->attempt(fn (): string => $this->transport->sendingArguments($arguments, function () use ($name): string {
            $this->client->send(new RawRequest('tools/call', ['name' => $name, 'arguments' => new stdClass]));

            return $this->transport->takeResult() ?? throw DownstreamRequestFailed::protocolError();
        }), isToolCall: true);
    }

    /**
     * Every entry a list method returns under the member, as the JSON object
     * the server sent for each, following its cursors page by page. Each
     * entry appears once, the last one listed under its name; entries
     * without a name are skipped.
     *
     * @param  string  $method  `tools/list` or `prompts/list`.
     * @param  string  $member  The member of each page's result that lists them.
     * @return list<string>
     *
     * @throws DownstreamRequestFailed
     */
    private function listEvery(string $method, string $member): array
    {
        $this->connect();

        return $this->attempt(function () use ($method, $member): array {
            $entries = [];
            $cursor = null;
            $seenCursors = [];

            for ($page = 1; ; $page++) {
                $this->client->send(new RawRequest($method, $cursor === null ? [] : ['cursor' => $cursor]));

                $result = $this->transport->takeResult() ?? throw DownstreamRequestFailed::protocolError();
                $listed = RawJson::elements(RawJson::member($result, $member) ?? '') ?? throw DownstreamRequestFailed::protocolError();

                foreach ($listed as $entry) {
                    $decoded = json_decode($entry);

                    if ($decoded instanceof stdClass && is_string($decoded->name ?? null)) {
                        $entries[$decoded->name] = $entry;
                    }
                }

                $cursor = json_decode(RawJson::member($result, 'nextCursor') ?? 'null');

                if (! is_string($cursor) || $cursor === '') {
                    return array_values($entries);
                }

                if (isset($seenCursors[$cursor]) || $page === self::MAX_PAGES) {
                    throw DownstreamRequestFailed::protocolError();
                }

                $seenCursors[$cursor] = true;
            }
        });
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
