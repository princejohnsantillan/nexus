<?php

declare(strict_types=1);

use App\Enums\DevAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Layout('layouts::public'), Title('Sign in')] class extends Component
{
    #[Computed]
    public function devSignInIsEnabled(): bool
    {
        return DevAccount::signInIsEnabled();
    }
};
