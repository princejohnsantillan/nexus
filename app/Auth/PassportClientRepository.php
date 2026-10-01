<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Passport's clients, looked up only by IDs that can be theirs.
 *
 * Client IDs are UUIDs, and anyone may send anything as one, to the token
 * endpoint or the authorization endpoint. Postgres refuses to compare text
 * that isn't a UUID with a UUID column, so looking it up would turn an
 * unknown client into a server error, reported with the ID it was sent.
 * Any other ID is simply no client: an `invalid_client` error.
 */
final class PassportClientRepository extends ClientRepository
{
    public function find(string|int $id): ?Client
    {
        return Str::isUuid((string) $id) ? parent::find($id) : null;
    }
}
