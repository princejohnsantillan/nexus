<?php

declare(strict_types=1);

namespace App\Actions;

use App\Connectors\Connector;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Enums\SignInMethod;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

class ConnectWithOAuth
{
    public function __construct(private readonly SaveNewConnection $saveNewConnection) {}

    /**
     * Save a gallery service as an OAuth Connection, within the account's
     * limit, ready to sign in: send the user to its `connections.connect`
     * route next. It needs sign-in until they finish.
     *
     * When the connector's server only accepts registered apps and this
     * deployment has none, the user brings their own: its client ID is
     * stored in the settings and its secret encrypted.
     *
     * @param  array{name: string, handle: string, description: string|null}  $details
     * @param  array{client_id: string, client_secret: string|null}|null  $ownApp  The user's own OAuth app, when they need one.
     *
     * @throws ValidationException under `limit` at the account's limit
     */
    public function handle(User $user, Connector $connector, array $details, #[SensitiveParameter] ?array $ownApp = null): Connection
    {
        if ($connector->whyUnavailable(SignInMethod::OAuth) !== null) {
            throw new LogicException("The {$connector->key} connector can't sign in with OAuth here.");
        }

        $connection = $user->connections()->make([
            'name' => $details['name'],
            'handle' => $details['handle'],
            'description' => $details['description'],
            'url' => $connector->url,
            'auth_type' => ConnectionAuthType::OAuth,
        ]);

        $connection->connector_key = $connector->key;
        $connection->status = ConnectionStatus::NeedsAuth;

        if ($ownApp !== null) {
            $connection->settings = ['oauth_client_id' => $ownApp['client_id']];
            $connection->secrets->put(['oauth_client_secret' => $ownApp['client_secret']]);
        }

        $this->saveNewConnection->handle($user, $connection);

        return $connection;
    }
}
