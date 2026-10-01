<?php

declare(strict_types=1);

use App\Actions\SaveNewConnection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Add connection')] class extends Component
{
    /**
     * What to tell the user when they can't add another Connection, or null when they can.
     */
    #[Computed]
    public function limitMessage(): ?string
    {
        $user = Auth::user() ?? throw new AuthenticationException;

        return $user->hasReachedConnectionLimit() ? SaveNewConnection::limitMessage() : null;
    }
};
