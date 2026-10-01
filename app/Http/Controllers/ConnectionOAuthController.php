<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Mcp\Downstream\DownstreamClients;
use App\Mcp\Downstream\OAuthClients;
use App\Mcp\Downstream\OAuthTokens;
use App\Models\Connection;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Throwable;

/**
 * Signs a connection in to its MCP server with OAuth.
 *
 * Nexus is the OAuth client here: it discovers the server's authorization
 * server, registers itself if needed, and stores the tokens encrypted with
 * the owner's key. One callback URL serves every connection, so each
 * pending sign-in is remembered by its `state`, which lets several run at
 * once, even for two accounts on the same server.
 */
class ConnectionOAuthController extends Controller
{
    protected const PENDING = 'nexus.oauth.pending';

    /** Abandoned sign-ins are forgotten once this many newer ones exist. */
    protected const MAX_PENDING = 5;

    public function connect(Request $request, Connection $connection, DownstreamClients $clients, OAuthClients $oauth): RedirectResponse
    {
        Gate::authorize('update', $connection);

        abort_unless($connection->auth_type === ConnectionAuthType::OAuth, 404);

        try {
            $clients->probe($connection);
        } catch (AuthorizationRequiredException $challenge) {
            $client = $oauth->for($connection, $challenge->resourceMetadataUrl(), $challenge->scope());

            try {
                $redirect = $client->redirect();
            } catch (OAuthException $exception) {
                return $this->fail($connection, ConnectionStatus::Error, "Couldn't start sign-in: {$exception->getMessage()}");
            }

            $connection->forceFill(['settings' => [...($connection->settings ?? []), 'oauth_resource' => $client->advertisedResource()]])->save();

            parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

            $pending = [...$request->session()->get(self::PENDING, []), (string) ($query['state'] ?? '') => $connection->id];
            $request->session()->put(self::PENDING, array_slice($pending, -self::MAX_PENDING, preserve_keys: true));

            return $redirect;
        } catch (Throwable $exception) {
            return $this->fail($connection, ConnectionStatus::Error, "Couldn't reach the server: {$exception->getMessage()}");
        }

        Notification::make()
            ->warning()
            ->title('This server doesn\'t ask for sign-in')
            ->body('Change the connection\'s sign-in method to "No authentication" or "Static header".')
            ->send();

        return $this->backTo($connection);
    }

    public function callback(Request $request, OAuthClients $oauth, OAuthTokens $tokens): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING, []);
        $state = (string) $request->query('state');
        $connection = isset($pending[$state]) ? Connection::query()->find($pending[$state]) : null;

        unset($pending[$state]);
        $request->session()->put(self::PENDING, $pending);

        if ($connection === null) {
            Notification::make()
                ->danger()
                ->title('Sign-in expired')
                ->body('This sign-in was already used or is too old. Start it again from the connection.')
                ->send();

            return redirect(ConnectionResource::getUrl('index', panel: 'app'));
        }

        Gate::authorize('update', $connection);

        $client = $oauth->for($connection);

        try {
            $tokenSet = $client->exchangeCallback();
        } catch (OAuthException $exception) {
            return $this->fail($connection, ConnectionStatus::NeedsAuth, "Sign-in failed: {$exception->getMessage()}");
        }

        $tokens->store($connection, $tokenSet);
        $connection->forceFill(['account_identity' => $client->accountHint()])->save();

        ConnectionResource::refreshTools($connection);

        return $this->backTo($connection);
    }

    public function clientMetadata(): JsonResponse
    {
        $url = route('oauth.client-metadata');

        return response()->json([
            'client_id' => $url,
            'client_name' => config('app.name'),
            'client_uri' => url('/'),
            'redirect_uris' => [route('oauth.callback')],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->setPublic()->setMaxAge(3600);
    }

    protected function fail(Connection $connection, ConnectionStatus $status, string $message): RedirectResponse
    {
        $connection->markStatus($status, $message);

        Notification::make()->danger()->title($connection->name)->body($message)->send();

        return $this->backTo($connection);
    }

    protected function backTo(Connection $connection): RedirectResponse
    {
        return redirect(ConnectionResource::getUrl('edit', ['record' => $connection], panel: 'app'));
    }
}
