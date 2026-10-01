<?php

namespace App\Policies;

use App\Models\ToolCallLog;
use App\Models\User;

class ToolCallLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ToolCallLog $log): bool
    {
        return $log->user_id === $user->id;
    }
}
