<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectionOAuth;

use App\ConnectionOAuth\ConnectionSignIn;
use App\Exceptions\ConnectionSignInFailed;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\ReturnToStar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends the user to sign a Connection in on its server's own sign-in page:
 * the Reconnect button, and the link MCP clients are given when a Connection
 * needs sign-in. A Connection that doesn't sign in with OAuth is fixed on its
 * own page instead, so the link leads there.
 *
 * From a Star's overview ("Add a connection"), `?star=` names the Star to go
 * back to; the sign-in keeps it in the session (ReturnToStar). If the
 * sign-in can't start, the user stays on the Connections page, still adding
 * to the Star.
 */
class StartSignInController extends Controller
{
    public function __invoke(Request $request, Connection $connection, ConnectionSignIn $signIn): RedirectResponse
    {
        if (! $connection->usesOAuth()) {
            return to_route('connections.show', $connection)->with('toast', [
                'variant' => 'warning',
                'text' => __('This Connection doesn\'t sign in with OAuth. Update its credentials here.'),
            ]);
        }

        $user = $request->user();
        $returnTo = $user instanceof User ? ReturnToStar::find($user, $request->query(ReturnToStar::QUERY)) : null;

        try {
            return redirect()->away($signIn->start($connection, $returnTo));
        } catch (ConnectionSignInFailed $failed) {
            $redirect = $returnTo instanceof Star ? redirect(ReturnToStar::addMoreUrl($returnTo)) : to_route('connections.show', $connection);

            return $redirect->with('toast', [
                'variant' => 'danger',
                'text' => __('Nexus couldn\'t start signing in. :reason', ['reason' => $failed->getMessage()]),
            ]);
        }
    }
}
