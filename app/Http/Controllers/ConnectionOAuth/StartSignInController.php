<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectionOAuth;

use App\ConnectionOAuth\ConnectionSignIn;
use App\Exceptions\ConnectionSignInFailed;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;

/**
 * Sends the user to sign a Connection in on its server's own sign-in page:
 * the Reconnect button, and the link MCP clients are given when a Connection
 * needs sign-in. A Connection that doesn't sign in with OAuth is fixed on its
 * own page instead, so the link leads there.
 */
class StartSignInController extends Controller
{
    public function __invoke(Connection $connection, ConnectionSignIn $signIn): RedirectResponse
    {
        if (! $connection->usesOAuth()) {
            return to_route('connections.show', $connection)->with('toast', [
                'variant' => 'warning',
                'text' => __('This Connection doesn\'t sign in with OAuth. Update its credentials here.'),
            ]);
        }

        try {
            return redirect()->away($signIn->start($connection));
        } catch (ConnectionSignInFailed $failed) {
            return to_route('connections.show', $connection)->with('toast', [
                'variant' => 'danger',
                'text' => __('Nexus couldn\'t start signing in. :reason', ['reason' => $failed->getMessage()]),
            ]);
        }
    }
}
