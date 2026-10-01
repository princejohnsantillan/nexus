<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Star;
use Illuminate\Support\Facades\DB;

class DeleteStar
{
    public function __construct(private readonly RevokeOAuthClients $revokeOAuthClients) {}

    /**
     * Delete the Star, first revoking every OAuth client that registered
     * with it, so none of their tokens outlives it. Its tokens, switches and
     * client bindings go with it; its activity entries keep a null Star.
     */
    public function handle(Star $star): void
    {
        DB::transaction(function () use ($star): void {
            $this->revokeOAuthClients->handle($star->oauthClients()->getQuery());

            $star->delete();
        });
    }
}
