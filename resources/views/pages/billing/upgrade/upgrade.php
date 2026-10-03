<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\Plan;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Upgrade')] class extends Component
{
    /**
     * The period picked on the Monthly / Yearly picker: a BillingPeriod
     * value. Yearly unless the link says `?period=month`.
     */
    public string $period = BillingPeriod::Year->value;

    public function mount(): void
    {
        $period = request()->query('period');

        $this->period = ((is_string($period) ? BillingPeriod::tryFrom($period) : null) ?? BillingPeriod::Year)->value;
    }

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * The period picked, or Yearly if the picker sent something else.
     */
    #[Computed]
    public function billingPeriod(): BillingPeriod
    {
        return BillingPeriod::tryFrom($this->period) ?? BillingPeriod::Year;
    }

    #[Computed]
    public function isPro(): bool
    {
        return $this->user->plan() === Plan::Pro;
    }
};
