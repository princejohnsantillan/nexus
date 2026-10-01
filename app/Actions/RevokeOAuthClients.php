<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\StarOAuthClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class RevokeOAuthClients
{
    /**
     * Revoke the OAuth clients these bindings are for, and everything Nexus
     * issued to them: their access tokens, those tokens' refresh tokens and
     * any authorization code not exchanged yet. Nothing a client holds works
     * afterwards, not even to renew its access token, so it has to register
     * and be approved again. The bindings themselves stay.
     *
     * @param  Builder<StarOAuthClient>  $bindings  Such as `$star->oauthClients()->getQuery()`.
     * @return int How many clients were revoked.
     */
    public function handle(Builder $bindings): int
    {
        return DB::transaction(function () use ($bindings): int {
            $clientIds = $bindings->select('client_id');

            RefreshToken::query()
                ->whereIn('access_token_id', Token::query()->whereIn('client_id', clone $clientIds)->select('id'))
                ->update(['revoked' => true]);

            Token::query()->whereIn('client_id', clone $clientIds)->update(['revoked' => true]);
            AuthCode::query()->whereIn('client_id', clone $clientIds)->update(['revoked' => true]);

            return Client::query()->whereIn('id', $clientIds)->where('revoked', false)->update(['revoked' => true]);
        });
    }
}
