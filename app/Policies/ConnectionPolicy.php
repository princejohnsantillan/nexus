<?php

namespace App\Policies;

use App\Models\Connection;
use App\Models\User;

class ConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Connection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->connections()->count() < config('nexus.limits.connections_per_user');
    }

    public function update(User $user, Connection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function delete(User $user, Connection $connection): bool
    {
        return $connection->user_id === $user->id;
    }
}
