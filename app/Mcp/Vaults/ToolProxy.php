<?php

namespace App\Mcp\Vaults;

use App\Enums\ToolCallStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Mcp\Downstream\ConnectionNeedsAuth;
use App\Mcp\Downstream\DownstreamClients;
use App\Models\ToolCallLog;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Exceptions\JsonRpcException;
use stdClass;
use Throwable;

/**
 * Forwards one tools/call to the downstream server and returns its result.
 *
 * Failures come back as tool results with isError set, so the agent can read
 * what went wrong and tell the user, instead of seeing a bare protocol error.
 */
class ToolProxy
{
    public function __construct(protected DownstreamClients $clients) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> The tools/call result.
     */
    public function call(VaultContext $context, ExposedTool $tool, array $arguments): array
    {
        $startedAt = hrtime(true);

        [$status, $result] = $this->forward($tool, $arguments);

        ToolCallLog::query()->create([
            'user_id' => $context->vault->user_id,
            'vault_id' => $context->vault->id,
            'vault_token_id' => $context->token->id,
            'connection_id' => $tool->connection->id,
            'tool_name' => $tool->name(),
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'response_bytes' => strlen((string) json_encode($result)),
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: ToolCallStatus, 1: array<string, mixed>}
     */
    protected function forward(ExposedTool $tool, array $arguments): array
    {
        $connection = $tool->connection;
        $definition = $tool->tool->definitionObject();

        try {
            $session = $this->clients->open($connection);

            try {
                $raw = $session->callTool($definition, $arguments);
            } catch (AuthorizationRequiredException $exception) {
                $raw = $this->clients->reopenAfterRejection($connection, $exception)->callTool($definition, $arguments);
            }
        } catch (ConnectionNeedsAuth|AuthorizationRequiredException) {
            return [ToolCallStatus::AuthRequired, $this->error(
                "The \"{$connection->name}\" connection in Nexus needs to be signed in again. "
                .'Ask the user to reconnect it at '.ConnectionResource::getUrl('edit', ['record' => $connection], panel: 'app').'.'
            )];
        } catch (JsonRpcException $exception) {
            return [ToolCallStatus::Failed, $this->error("The \"{$connection->name}\" server returned an error: {$exception->getMessage()}")];
        } catch (Throwable $exception) {
            report($exception);

            return [ToolCallStatus::Failed, $this->error("Nexus could not complete the call to \"{$connection->name}\": {$exception->getMessage()}")];
        }

        if (($raw->resultType ?? 'complete') !== 'complete') {
            return [ToolCallStatus::Failed, $this->error("The \"{$connection->name}\" server asked for interactive input, which Nexus does not support yet.")];
        }

        return [
            ($raw->isError ?? false) === true ? ToolCallStatus::ToolError : ToolCallStatus::Ok,
            $this->passthrough($raw, $tool),
        ];
    }

    /**
     * The server's result, unchanged except for one thing: when the vault
     * has several accounts of this service, a first line says which account
     * answered. Otherwise an empty search of the wrong mailbox looks exactly
     * like "nothing found".
     *
     * @return array<string, mixed>
     */
    protected function passthrough(stdClass $raw, ExposedTool $tool): array
    {
        $result = (array) $raw;

        unset($result['resultType']);

        $result['content'] = is_array($result['content'] ?? null) ? $result['content'] : [];

        if ($tool->hasSiblings) {
            array_unshift($result['content'], [
                'type' => 'text',
                'text' => "From {$tool->connection->accountSummary()} ({$tool->connection->handle}).",
            ]);
        }

        if (isset($result['_meta'])) {
            $meta = (array) $result['_meta'];
            unset($meta[MetaKey::SERVER_INFO->value]);
            $result['_meta'] = $meta;
        }

        return $result;
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    protected function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
