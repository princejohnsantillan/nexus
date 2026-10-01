<?php

declare(strict_types=1);

use App\Models\Connection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connections')] class extends Component
{
    /**
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        $user = Auth::user() ?? throw new AuthenticationException;

        return $user->connections()->withCount('tools')->orderBy('name')->orderBy('id')->get();
    }
};
