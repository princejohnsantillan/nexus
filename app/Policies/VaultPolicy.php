<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vault;

class VaultPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vault $vault): bool
    {
        return $vault->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->vaults()->count() < config('nexus.limits.vaults_per_user');
    }

    public function update(User $user, Vault $vault): bool
    {
        return $vault->user_id === $user->id;
    }

    public function delete(User $user, Vault $vault): bool
    {
        return $vault->user_id === $user->id;
    }
}
