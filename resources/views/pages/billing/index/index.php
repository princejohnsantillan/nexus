<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Billing')] class extends Component
{
    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    #[Computed]
    public function plan(): Plan
    {
        return $this->user->plan();
    }

    /**
     * How many Stars the user has, against their plan's limit.
     */
    #[Computed]
    public function starCount(): int
    {
        return $this->user->stars()->count();
    }

    /**
     * How many Connections the user has, against their plan's limit.
     */
    #[Computed]
    public function connectionCount(): int
    {
        return $this->user->connections()->count();
    }
};
