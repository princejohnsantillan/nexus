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
use App\Stars\StarPrompt;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;

/**
 * Forwards one `prompts/get` through a Star to its Connection's server, and
 * records it as activity.
 *
 * The arguments go out and the result comes back as the exact JSON the
 * client and the server sent. Prompts have no error result as tools do, so
 * when the server can't be asked, the answer is JSON-RPC error `-32603`
 * with Nexus's own message, which clients show the user; when the
 * Connection needs signing in again, the message gives its reconnect link
 * (`connections.connect`), as a failed tool call's does.
 */
final readonly class PromptProxy
{
    public function __construct(
        private DownstreamClient $downstream,
        private RecordActivity $recordActivity,
    ) {}

    /**
     * Get the prompt and return the server's result as a JSON object.
     *
     * @param  string  $arguments  The arguments, a JSON object.
     * @param  int  $startedAt  When the request arrived, an `hrtime(true)` reading.
     * @param  int|string  $requestId  The id of the client's request, which an error answers.
     *
     * @throws JsonRpcException when the server can't be asked
     */
    public function get(StarCaller $caller, StarPrompt $prompt, string $arguments, int $startedAt, int|string $requestId): string
    {
        $status = ActivityStatus::Error;

        try {
            $result = $this->downstream->session($prompt->connection)->getPrompt($prompt->prompt->name, $arguments);
            $status = json_decode(RawJson::member($result, 'resultType') ?? '"complete"') === 'complete' ? ActivityStatus::Ok : ActivityStatus::Error;
        } catch (DownstreamRequestFailed $failed) {
            $status = $this->statusFor($failed->failure);

            throw new JsonRpcException($this->failed($prompt, $failed), ErrorCode::INTERNAL_ERROR->value, $requestId);
        } finally {
            $this->recordActivity->handle(
                caller: $caller,
                kind: ActivityKind::Prompt,
                exposedName: $prompt->name,
                connection: $prompt->connection,
                downstreamName: $prompt->prompt->name,
                status: $status,
                startedAt: $startedAt,
            );
        }

        if ($status !== ActivityStatus::Ok) {
            throw new JsonRpcException(__('Nexus could not get :prompt from :connection. The server asked for more input before answering, which Nexus does not support yet.', [
                'prompt' => $prompt->name,
                'connection' => $prompt->connection->name,
            ]), ErrorCode::INTERNAL_ERROR->value, $requestId);
        }

        return $result;
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
     * Nexus's own message for a failed request, never text from the server.
     */
    private function failed(StarPrompt $prompt, DownstreamRequestFailed $failed): string
    {
        $message = __('Nexus could not get :prompt from :connection. :reason', [
            'prompt' => $prompt->name,
            'connection' => $prompt->connection->name,
            'reason' => $failed->getMessage(),
        ]);

        if ($failed->failure === DownstreamFailure::NeedsSignIn) {
            $message .= ' '.__('The :connection Connection needs signing in again: ask the user to reconnect it in Nexus at :url', [
                'connection' => $prompt->connection->name,
                'url' => route('connections.connect', $prompt->connection),
            ]);
        }

        return $message;
    }
}
