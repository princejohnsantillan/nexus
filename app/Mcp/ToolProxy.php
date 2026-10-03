<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Actions\CountToolCall;
use App\Actions\RecordActivity;
use App\Downstream\DownstreamClient;
use App\Downstream\RawJson;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Exceptions\WeeklyToolCallLimitReached;
use App\Jobs\RefreshCatalogInBackground;
use App\Stars\StarTool;
use Laravel\Mcp\Enums\ErrorCode;
use stdClass;

/**
 * Forwards one tool call through a Star to its Connection's server, and
 * records it as activity.
 *
 * The arguments go out and the result comes back as the exact JSON the
 * client and the server sent, a tool error (`isError: true`) included.
 * When the server can't be called, the result is a tool error with Nexus's
 * own message, so the agent can tell the user what went wrong; when the
 * Connection needs signing in again, the message gives its reconnect link
 * (`connections.connect`), which starts an OAuth sign-in or, for a header
 * or token, opens the Connection's page.
 *
 * Every call it forwards counts toward the account's weekly tool calls
 * (CountToolCall), whether it then succeeds or not. It is counted once the
 * session has checked it can sign in, and before anything is sent to the
 * server, so a call to a Connection that isn't signed in (or whose sign-in
 * can't be renewed) doesn't count. Once a Free account has used its calls
 * for the week, the call isn't forwarded: the result is a tool error saying
 * so, when they reset and where to upgrade, and the call is recorded as
 * Limited.
 *
 * When the server says it doesn't know the tool, the Connection's catalog
 * no longer matches the server, so a background refresh is queued once
 * the response has been sent.
 */
final readonly class ToolProxy
{
    public function __construct(
        private DownstreamClient $downstream,
        private CountToolCall $countToolCall,
        private RecordActivity $recordActivity,
    ) {}

    /**
     * Call the tool and return its result as a JSON object.
     *
     * @param  string  $arguments  The arguments, a JSON object.
     * @param  int  $startedAt  When the call arrived, an `hrtime(true)` reading.
     */
    public function call(StarCaller $caller, StarTool $tool, string $arguments, int $startedAt): string
    {
        $status = ActivityStatus::Error;

        try {
            [$status, $result] = $this->forward($caller, $tool, $arguments);

            return $result;
        } finally {
            $this->recordActivity->handle(
                caller: $caller,
                kind: ActivityKind::Tool,
                exposedName: $tool->name,
                connection: $tool->connection,
                downstreamName: $tool->tool->name,
                status: $status,
                startedAt: $startedAt,
            );
        }
    }

    /**
     * @return array{ActivityStatus, string}
     */
    private function forward(StarCaller $caller, StarTool $tool, string $arguments): array
    {
        $session = $this->downstream->session($tool->connection);

        try {
            $session->signIn();
            $this->countToolCall->handle($caller->star->user);
            $result = $session->callTool($tool->tool->name, $arguments);
        } catch (WeeklyToolCallLimitReached $limitReached) {
            return [ActivityStatus::Limited, $this->limitReached($limitReached)];
        } catch (DownstreamRequestFailed $failed) {
            if ($this->refusedAsInvalidParams($failed)) {
                $this->refreshCatalogLater($tool);
            }

            return [$this->statusFor($failed->failure), $this->failed($tool, $failed)];
        }

        if (json_decode(RawJson::member($result, 'resultType') ?? '"complete"') !== 'complete') {
            return [ActivityStatus::Error, $this->toolError(__('Nexus could not call :tool on :connection. The server asked for more input before answering, which Nexus does not support yet.', [
                'tool' => $tool->name,
                'connection' => $tool->connection->name,
            ]))];
        }

        $isError = json_decode(RawJson::member($result, 'isError') ?? 'false') === true;

        if ($isError && $this->saysToolIsUnknown($tool, $result)) {
            $this->refreshCatalogLater($tool);
        }

        return [$isError ? ActivityStatus::Error : ActivityStatus::Ok, $result];
    }

    /**
     * Whether the server refused the call as "invalid params" (JSON-RPC
     * -32602): the error the MCP specification has a server answer a call
     * to a tool it doesn't have with. Servers before 2025-11-25 also refuse
     * arguments that don't fit the tool's schema with it, as when the tool
     * changed; a refresh after arguments that were simply wrong finds
     * nothing new.
     */
    private function refusedAsInvalidParams(DownstreamRequestFailed $failed): bool
    {
        return $failed->failure === DownstreamFailure::ToolError && $failed->jsonRpcCode === ErrorCode::INVALID_PARAMS->value;
    }

    /**
     * Whether a tool error's text says the server doesn't know the tool, as
     * servers built on the MCP SDKs for Python ("Unknown tool: search") and
     * TypeScript ("Tool search not found") answer, rather than with the
     * JSON-RPC "invalid params" error the specification asks for. The text
     * is only matched, never kept.
     */
    private function saysToolIsUnknown(StarTool $tool, string $result): bool
    {
        $content = json_decode(RawJson::member($result, 'content') ?? '[]');
        $pattern = '/\bunknown tool\b|\btool\W+(?:'.preg_quote($tool->tool->name, '/').'\W+)?not found\b/i';

        return array_any(is_array($content) ? $content : [], fn (mixed $block): bool => $block instanceof stdClass && ($block->type ?? null) === 'text' && is_string($block->text ?? null) && preg_match($pattern, $block->text) === 1);
    }

    /**
     * Queue a background refresh of the tool's Connection once the response
     * has been sent.
     */
    private function refreshCatalogLater(StarTool $tool): void
    {
        $connectionId = $tool->connection->id;

        defer(function () use ($connectionId): void {
            RefreshCatalogInBackground::dispatch($connectionId);
        });
    }

    private function statusFor(DownstreamFailure $failure): ActivityStatus
    {
        return match ($failure) {
            DownstreamFailure::NeedsSignIn => ActivityStatus::NeedsAuth,
            DownstreamFailure::Timeout => ActivityStatus::Timeout,
            DownstreamFailure::Unreachable, DownstreamFailure::ProtocolError, DownstreamFailure::ToolError => ActivityStatus::Error,
        };
    }

    /**
     * Nexus's own message for a failed call, never text from the server.
     */
    private function failed(StarTool $tool, DownstreamRequestFailed $failed): string
    {
        $message = __('Nexus could not call :tool on :connection. :reason', [
            'tool' => $tool->name,
            'connection' => $tool->connection->name,
            'reason' => $failed->getMessage(),
        ]);

        if ($failed->failure === DownstreamFailure::NeedsSignIn) {
            $message .= ' '.__('The :connection Connection needs signing in again: ask the user to reconnect it in Nexus at :url', [
                'connection' => $tool->connection->name,
                'url' => route('connections.connect', $tool->connection),
            ]);
        }

        return $this->toolError($message);
    }

    /**
     * Nexus's own message for a call refused by the weekly limit, with where
     * to upgrade.
     */
    private function limitReached(WeeklyToolCallLimitReached $limitReached): string
    {
        return $this->toolError($limitReached->getMessage().' '.__('Upgrade to Pro for unlimited tool calls: :url', [
            'url' => route('billing.upgrade'),
        ]));
    }

    /**
     * A tool result reporting an error, with the message as its text.
     */
    private function toolError(string $message): string
    {
        return json_encode([
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
