<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\StarOAuthClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class DeleteAccount
{
    public function __construct(
        private readonly SignOut $signOut,
        private readonly RevokeOAuthClients $revokeOAuthClients,
    ) {}

    /**
     * Sign the user out and delete them. Everything a user owns cascades from the
     * users table, including their data key and their sign-in identities.
     *
     * Passport's tables don't cascade from it, so the OAuth clients registered
     * with the user's Stars are revoked first, and so is every access and refresh
     * token issued to the user.
     *
     * Signing out comes first: it rewrites the user's remember token, and saving
     * that on an already deleted model would insert the user again.
     */
    public function handle(User $user): void
    {
        $this->signOut->handle();

        DB::transaction(function () use ($user): void {
            $this->revokeOAuthClients->handle(StarOAuthClient::query()->whereIn('star_id', $user->stars()->select('id')));

            RefreshToken::query()
                ->whereIn('access_token_id', Token::query()->where('user_id', $user->id)->select('id'))
                ->update(['revoked' => true]);

            Token::query()->where('user_id', $user->id)->update(['revoked' => true]);

            $user->delete();
        });
    }
}
