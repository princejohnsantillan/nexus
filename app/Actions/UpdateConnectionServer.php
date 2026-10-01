<?php

declare(strict_types=1);

namespace App\Actions;

use App\ConnectionOAuth\SignInLock;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class UpdateConnectionServer
{
    public function __construct(
        private readonly RefreshCatalog $refreshCatalog,
        private readonly SignInLock $signInLock,
    ) {}

    /**
     * Change where a Connection's server is and how Nexus signs in to it,
     * then reload its tools.
     *
     * A new URL clears every stored credential, so none is ever sent to a
     * server it wasn't meant for, and empties the catalog, whose tools
     * belonged to the old server. A header sign-in then needs its value
     * again, and an OAuth sign-in starts over: the server's registered client
     * is forgotten too. Switching to no auth clears the header; switching
     * away from OAuth clears its tokens and clients.
     *
     * An OAuth sign-in also ends when the user's own OAuth app changes, since
     * its tokens were issued to the old one. The account the Connection
     * signed in as is forgotten when the server, the sign-in method or the
     * header value changes, since it may be another account now; the
     * refresh, or the next OAuth sign-in, detects it again. An OAuth
     * Connection that isn't signed in needs sign-in, and its tools aren't
     * reloaded: send the user to its `connections.connect` route next.
     *
     * The change holds the Connection's SignInLock and applies to the
     * Connection as re-read once it has it, so it waits for a token renewal
     * in progress and never writes back tokens that renewal replaced.
     *
     * @param  array{url: string, auth_type: ConnectionAuthType, header_name: string|null, oauth_client_id?: string|null}  $server
     * @param  string|null  $headerValue  A new value for a header sign-in, or null to keep the stored one.
     * @param  string|null  $clientSecret  A new secret for the user's own OAuth app, or null to keep the stored one.
     * @return bool Whether the tools loaded.
     *
     * @throws ValidationException when a header sign-in has no value to send, or (under `server`) a renewal holds the lock too long
     */
    public function handle(Connection $connection, array $server, #[SensitiveParameter] ?string $headerValue = null, #[SensitiveParameter] ?string $clientSecret = null): bool
    {
        try {
            $awaitsSignIn = $this->signInLock->hold($connection, fn (): bool => $this->update($connection, $server, $headerValue, $clientSecret));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['server' => __('Nexus is renewing this Connection\'s sign-in. Try again in a moment.')]);
        }

        return ! $awaitsSignIn && $this->refreshCatalog->handle($connection);
    }

    /**
     * Apply the change to the Connection, as re-read under the lock.
     *
     * @param  array{url: string, auth_type: ConnectionAuthType, header_name: string|null, oauth_client_id?: string|null}  $server
     * @return bool Whether the Connection now waits for the user to sign in.
     *
     * @throws ValidationException when a header sign-in has no value to send
     */
    private function update(Connection $connection, array $server, #[SensitiveParameter] ?string $headerValue, #[SensitiveParameter] ?string $clientSecret): bool
    {
        $urlChanged = $server['url'] !== $connection->url;

        if ($server['auth_type'] === ConnectionAuthType::Header && $headerValue === null
            && ($urlChanged || $connection->auth_type !== ConnectionAuthType::Header || $connection->headerValue() === '')) {
            throw ValidationException::withMessages([
                'headerValue' => $urlChanged
                    ? __('Enter the header value again. Nexus clears stored credentials when the URL changes.')
                    : __('Enter the header value Nexus should send.'),
            ]);
        }

        if ($urlChanged) {
            $connection->secrets->put(array_fill_keys(array_keys($connection->secrets->all()), null));
        }

        $connection->url = $server['url'];
        $connection->auth_type = $server['auth_type'];

        $settings = Arr::except($connection->settings ?? [], 'header_name');

        if ($server['auth_type'] === ConnectionAuthType::Header) {
            $settings['header_name'] = $server['header_name'] ?? Connection::DEFAULT_HEADER_NAME;

            if ($headerValue !== null) {
                $connection->secrets->put(['header_value' => $headerValue]);
            }
        } else {
            $connection->secrets->put(['header_value' => null]);
        }

        $connection->settings = $settings === [] ? null : $settings;

        $this->updateOAuth($connection, $server['oauth_client_id'] ?? null, $clientSecret, $urlChanged);

        if ($urlChanged || $connection->isDirty('auth_type') || $headerValue !== null) {
            $connection->account_identity = null;
        }

        $awaitsSignIn = $connection->usesOAuth() && ! $connection->hasAccessToken();

        DB::transaction(function () use ($connection, $urlChanged, $awaitsSignIn): void {
            if ($urlChanged) {
                $connection->tools()->delete();

                $connection->forceFill([
                    'status' => ConnectionStatus::Pending,
                    'last_error' => null,
                    'catalog_refreshed_at' => null,
                ]);
            }

            if ($awaitsSignIn) {
                $connection->forceFill(['status' => ConnectionStatus::NeedsAuth, 'last_error' => null]);
            }

            $connection->save();
        });

        return $awaitsSignIn;
    }

    /**
     * Keep the Connection's OAuth sign-in and clients only while they still
     * apply: to an OAuth Connection, on the same server, through the same
     * OAuth app of the user's own.
     *
     * @param  string|null  $clientId  The client ID of the user's own OAuth app, or null for none.
     */
    private function updateOAuth(Connection $connection, ?string $clientId, #[SensitiveParameter] ?string $clientSecret, bool $urlChanged): void
    {
        if (! $connection->usesOAuth() || $urlChanged) {
            $connection->forgetOAuthSignIn();
            $connection->forgetRegisteredClient();
        }

        if (! $connection->usesOAuth()) {
            $clientId = null;
        }

        if ($clientId !== $connection->oauthClientId()) {
            $connection->forgetOAuthSignIn();
            $connection->secrets->put(['oauth_client_secret' => null]);

            $settings = Arr::except($connection->settings ?? [], 'oauth_client_id');

            if ($clientId !== null) {
                $settings['oauth_client_id'] = $clientId;
            }

            $connection->settings = $settings === [] ? null : $settings;
        }

        if ($clientId !== null && $clientSecret !== null) {
            $connection->secrets->put(['oauth_client_secret' => $clientSecret]);
        }
    }
}
