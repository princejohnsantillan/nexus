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
    public function __construct(
        private readonly SaveNewConnection $saveNewConnection,
        private readonly RefreshCatalog $refreshCatalog,
    ) {}

    /**
     * Save a remote MCP server as one of the user's Connections, within the
     * account's limit, and load its tools straight away. The Connection is
     * saved even when its tools don't load; its status and last error then
     * say why.
     *
     * @param  array{name: string, handle: string, description: string|null, url: string, auth_type: ConnectionAuthType, header_name: string|null}  $attributes
     * @param  string|null  $headerValue  What a header sign-in sends, stored encrypted.
     *
     * @throws ValidationException when the user already has as many Connections as an account may
     */
    public function handle(User $user, array $attributes, #[SensitiveParameter] ?string $headerValue = null): Connection
    {
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

        $this->saveNewConnection->handle($user, $connection);

        $this->refreshCatalog->handle($connection);

        return $connection;
    }
}
