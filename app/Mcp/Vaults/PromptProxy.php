<?php

namespace App\Mcp\Vaults;

use App\Enums\ToolCallStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Mcp\Downstream\ConnectionNeedsAuth;
use App\Mcp\Downstream\DownstreamClients;
use App\Models\ToolCallLog;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Throwable;

/**
 * Forwards one prompts/get to the downstream server and returns its result
 * unchanged. Prompts have no error result like tools do, so failures are
 * JSON-RPC errors with a message the client shows to the user.
 */
class PromptProxy
{
    public function __construct(protected DownstreamClients $clients) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> The prompts/get result.
     *
     * @throws JsonRpcException
     */
    public function get(VaultContext $context, ExposedPrompt $prompt, array $arguments, int|string $requestId): array
    {
        $startedAt = hrtime(true);
        $status = ToolCallStatus::Ok;

        try {
            return $result = $this->forward($prompt, $arguments, $requestId, $status);
        } finally {
            ToolCallLog::query()->create([
                'user_id' => $context->vault->user_id,
                'vault_id' => $context->vault->id,
                'kind' => 'prompt',
                'vault_token_id' => $context->token?->id,
                'via' => $context->via,
                'connection_id' => $prompt->connection->id,
                'tool_name' => $prompt->name(),
                'status' => $status,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'response_bytes' => isset($result) ? strlen((string) json_encode($result)) : 0,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonRpcException
     */
    protected function forward(ExposedPrompt $prompt, array $arguments, int|string $requestId, ToolCallStatus &$status): array
    {
        $connection = $prompt->connection;

        try {
            $session = $this->clients->open($connection);

            try {
                $raw = $session->getPrompt($prompt->prompt->name, $arguments);
            } catch (AuthorizationRequiredException $exception) {
                $raw = $this->clients->reopenAfterRejection($connection, $exception)->getPrompt($prompt->prompt->name, $arguments);
            }
        } catch (ConnectionNeedsAuth|AuthorizationRequiredException) {
            $status = ToolCallStatus::AuthRequired;

            throw new JsonRpcException(
                "The \"{$connection->name}\" connection in Nexus needs to be signed in again. Reconnect it at "
                .ConnectionResource::getUrl('edit', ['record' => $connection], panel: 'app').'.',
                ErrorCode::INTERNAL_ERROR->value,
                $requestId,
            );
        } catch (JsonRpcException $exception) {
            $status = ToolCallStatus::Failed;

            throw new JsonRpcException("The \"{$connection->name}\" server returned an error: {$exception->getMessage()}", $exception->getCode() ?: ErrorCode::INTERNAL_ERROR->value, $requestId);
        } catch (Throwable $exception) {
            report($exception);
            $status = ToolCallStatus::Failed;

            throw new JsonRpcException("Nexus could not get the prompt from \"{$connection->name}\": {$exception->getMessage()}", ErrorCode::INTERNAL_ERROR->value, $requestId);
        }

        $result = (array) $raw;
        unset($result['resultType']);
        $result['messages'] = is_array($result['messages'] ?? null) ? $result['messages'] : [];

        if (isset($result['_meta'])) {
            $meta = (array) $result['_meta'];
            unset($meta[MetaKey::SERVER_INFO->value]);
            $result['_meta'] = $meta;
        }

        return $result;
    }
}
