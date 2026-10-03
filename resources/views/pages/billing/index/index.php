<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * How many tool calls the user's Stars have forwarded this billing week,
     * against their plan's weekly limit.
     */
    #[Computed]
    public function toolCallsThisWeek(): int
    {
        return $this->user->toolCallsThisWeek();
    }

    /**
     * The user's paid payments, newest first. Pending and expired checkouts
     * charged nothing, so they aren't listed.
     *
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->user->payments()
            ->where('status', PaymentStatus::Paid)
            ->latest('paid_at')
            ->latest('id')
            ->get();
    }
};
