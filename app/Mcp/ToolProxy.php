<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Actions\RecordActivity;
use App\Downstream\DownstreamClient;
use App\Downstream\RawJson;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Stars\StarTool;

/**
 * Forwards one tool call through a Star to its Connection's server, and
 * records it as activity.
 *
 * The arguments go out and the result comes back as the exact JSON the
 * client and the server sent, a tool error (`isError: true`) included.
 * When the server can't be called, the result is a tool error with Nexus's
 * own message, so the agent can tell the user what went wrong; when the
 * Connection needs signing in again, the message says where.
 */
final readonly class ToolProxy
{
    public function __construct(
        private DownstreamClient $downstream,
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
            [$status, $result] = $this->forward($tool, $arguments);

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
    private function forward(StarTool $tool, string $arguments): array
    {
        try {
            $result = $this->downstream->session($tool->connection)->callTool($tool->tool->name, $arguments);
        } catch (DownstreamRequestFailed $failed) {
            return [$this->statusFor($failed->failure), $this->failed($tool, $failed)];
        }

        if (json_decode(RawJson::member($result, 'resultType') ?? '"complete"') !== 'complete') {
            return [ActivityStatus::Error, $this->toolError(__('Nexus could not call :tool on :connection. The server asked for more input before answering, which Nexus does not support yet.', [
                'tool' => $tool->name,
                'connection' => $tool->connection->name,
            ]))];
        }

        $isError = json_decode(RawJson::member($result, 'isError') ?? 'false') === true;

        return [$isError ? ActivityStatus::Error : ActivityStatus::Ok, $result];
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
                'url' => route('connections.show', $tool->connection),
            ]);
        }

        return $this->toolError($message);
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
