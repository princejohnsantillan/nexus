<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectionOAuth;

use App\ConnectionOAuth\ConnectionSignIn;
use App\Enums\ConnectionStatus;
use App\Exceptions\ConnectionSignInFailed;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where every Connection's server sends the user back after they approve
 * (or refuse) Nexus: finishes the sign-in and shows the Connection.
 */
class SignInCallbackController extends Controller
{
    public function __invoke(Request $request, ConnectionSignIn $signIn): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        try {
            $connection = $signIn->finish($user, $request->query->all());
        } catch (ConnectionSignInFailed $failed) {
            $redirect = $failed->connection instanceof Connection ? to_route('connections.show', $failed->connection) : to_route('connections.index');

            return $redirect->with('toast', ['variant' => 'danger', 'text' => $failed->getMessage()]);
        }

        return to_route('connections.show', $connection)->with('toast', $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => trans_choice('Signed in. Nexus loaded :count tool.|Signed in. Nexus loaded :count tools.', $connection->tools()->count())]
            : ['variant' => 'warning', 'text' => __('Signed in, but Nexus couldn\'t load its tools. The Connection page says why.')]);
    }
}
