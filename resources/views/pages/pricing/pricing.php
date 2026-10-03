<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Layout('layouts::public'), Title('Pricing')] class extends Component
{
    /**
     * The period picked on the Monthly / Yearly picker: a BillingPeriod
     * value. Yearly first, as on the Upgrade page.
     */
    public string $period = BillingPeriod::Year->value;

    /**
     * The period picked, or Yearly if the picker sent something else.
     */
    #[Computed]
    public function billingPeriod(): BillingPeriod
    {
        return BillingPeriod::tryFrom($this->period) ?? BillingPeriod::Year;
    }
};
