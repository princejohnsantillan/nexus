<?php

declare(strict_types=1);

namespace App\Http\Controllers\StarOAuth;

use App\Actions\SignOut;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Not you?" on the consent screen. The form carries the authorization
 * request's query; this signs the user out and sends them back to the same
 * consent screen, which sends a guest to sign in and remembers to come back
 * afterwards, so whoever signs in next sees the same request. The way back
 * is always the consent screen: only the query comes from the form.
 */
class SwitchAccountController extends Controller
{
    public function __invoke(Request $request, SignOut $signOut): RedirectResponse
    {
        $signOut->handle();

        return to_route('passport.authorizations.authorize', $request->query->all());
    }
}
