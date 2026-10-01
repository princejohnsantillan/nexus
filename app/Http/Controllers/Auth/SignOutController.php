<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SignOut;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class SignOutController extends Controller
{
    public function __invoke(SignOut $signOut): RedirectResponse
    {
        $signOut->handle();

        return to_route('home')->with('toast', ['variant' => 'success', 'text' => __("You're signed out.")]);
    }
}
