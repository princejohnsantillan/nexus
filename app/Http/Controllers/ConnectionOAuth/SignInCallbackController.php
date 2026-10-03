<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectionOAuth;

use App\ConnectionOAuth\ConnectionSignIn;
use App\Enums\ConnectionStatus;
use App\Exceptions\ConnectionSignInFailed;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\ReturnToStar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where every Connection's server sends the user back after they approve
 * (or refuse) Nexus: finishes the sign-in and shows the Connection.
 *
 * A sign-in started from a Star's overview goes back to the Star instead,
 * with the Connection added to it, or, when it failed or the tools didn't
 * load, to the Connections page, still adding to the Star (ReturnToStar).
 */
class SignInCallbackController extends Controller
{
    public function __invoke(Request $request, ConnectionSignIn $signIn, ReturnToStar $returnToStar): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $returnTo = $signIn->returnTarget($user, $request->query->all());

        try {
            $connection = $signIn->finish($user, $request->query->all());
        } catch (ConnectionSignInFailed $failed) {
            $redirect = match (true) {
                $returnTo instanceof Star => redirect(ReturnToStar::addMoreUrl($returnTo)),
                $failed->connection instanceof Connection => to_route('connections.show', $failed->connection),
                default => to_route('connections.index'),
            };

            return $redirect->with('toast', ['variant' => 'danger', 'text' => $failed->getMessage()]);
        }

        if ($returnTo instanceof Star) {
            ['url' => $url, 'toast' => $toast] = $returnToStar->finish($returnTo, $connection);

            return redirect($url)->with('toast', $toast);
        }

        return to_route('connections.show', $connection)->with('toast', $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => trans_choice('Signed in. Nexus loaded :count tool.|Signed in. Nexus loaded :count tools.', $connection->tools()->count())]
            : ['variant' => 'warning', 'text' => __('Signed in, but Nexus couldn\'t load its tools. The Connection page says why.')]);
    }
}
