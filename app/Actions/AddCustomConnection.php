<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ConnectionAuthType;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AddCustomConnection
{
    public function __construct(private readonly RefreshCatalog $refreshCatalog) {}

    /**
     * Save a remote MCP server as one of the user's Connections and load its
     * tools straight away. The Connection is saved even when its tools don't
     * load; its status and last error then say why.
     *
     * @param  array{name: string, handle: string, description: string|null, url: string, auth_type: ConnectionAuthType, header_name: string|null}  $attributes
     * @param  string|null  $headerValue  What a header sign-in sends, stored encrypted.
     *
     * @throws ValidationException when the user already has as many Connections as an account may
     */
    public function handle(User $user, array $attributes, #[SensitiveParameter] ?string $headerValue = null): Connection
    {
        if ($user->hasReachedConnectionLimit()) {
            throw ValidationException::withMessages(['limit' => self::limitMessage()]);
        }

        $connection = $user->connections()->make([
            'name' => $attributes['name'],
            'handle' => $attributes['handle'],
            'description' => $attributes['description'],
            'url' => $attributes['url'],
            'auth_type' => $attributes['auth_type'],
        ]);

        if ($attributes['auth_type'] === ConnectionAuthType::Header) {
            $connection->settings = ['header_name' => $attributes['header_name'] ?? Connection::DEFAULT_HEADER_NAME];
            $connection->secrets->put(['header_value' => $headerValue]);
        }

        $connection->save();

        $this->refreshCatalog->handle($connection);

        return $connection;
    }

    /**
     * What a user who has reached the Connections limit is told.
     */
    public static function limitMessage(): string
    {
        return __('You have :limit Connections, the most an account can have. Delete one to add another.', [
            'limit' => config()->integer('nexus.limits.connections_per_user'),
        ]);
    }
}
