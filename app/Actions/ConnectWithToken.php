<?php

declare(strict_types=1);

namespace App\Actions;

use App\Connectors\Connector;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

class ConnectWithToken
{
    public function __construct(
        private readonly SaveNewConnection $saveNewConnection,
        private readonly RefreshCatalog $refreshCatalog,
        private readonly DeleteConnection $deleteConnection,
    ) {}

    /**
     * Connect a gallery service with a token the user pasted, within the
     * account's limit, and load its tools straight away to check the token.
     *
     * The Connection is a header sign-in to the connector's server: the
     * token, after the connector's value prefix (`Bearer ` unless it says
     * otherwise), is stored encrypted and sent in its header. If the server
     * refuses the token, the Connection is removed again and the user is told
     * under `token`. Any other failure to load the tools keeps the
     * Connection; its status and last error then say why.
     *
     * @param  array{name: string, handle: string, description: string|null}  $details
     *
     * @throws ValidationException under `limit` at the account's limit, or under `token` when the server refuses the token
     */
    public function handle(User $user, Connector $connector, array $details, #[SensitiveParameter] string $token): Connection
    {
        $tokenSignIn = $connector->token ?? throw new LogicException("The {$connector->key} connector doesn't accept tokens.");

        $connection = $user->connections()->make([
            'name' => $details['name'],
            'handle' => $details['handle'],
            'description' => $details['description'],
            'url' => $connector->url,
            'auth_type' => ConnectionAuthType::Header,
            'settings' => ['header_name' => $tokenSignIn->headerName],
        ]);

        $connection->connector_key = $connector->key;
        $connection->secrets->put(['header_value' => $tokenSignIn->headerValue($token)]);

        $this->saveNewConnection->handle($user, $connection);

        if (! $this->refreshCatalog->handle($connection) && $connection->status === ConnectionStatus::NeedsAuth) {
            $this->deleteConnection->handle($connection);

            throw ValidationException::withMessages([
                'token' => __(':name didn\'t accept this token. Check that you copied all of it and that it hasn\'t expired or been revoked.', ['name' => $connector->name]),
            ]);
        }

        return $connection;
    }
}
