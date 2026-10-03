{{--
    Monthly or Yearly, as a segmented radio group, with what Yearly saves
    on its badge. Bind it to a BillingPeriod value, e.g.
    wire:model.live="period".
--}}
<flux:radio.group variant="segmented" {{ $attributes->class('h-auto! w-max gap-0.5 rounded-lg! border border-zinc-200 bg-zinc-50! p-[3px]! dark:border-white/10 dark:bg-white/5!')->merge(['aria-label' => __('Billing period')]) }} data-billing-period-picker>
    @foreach (App\Enums\BillingPeriod::cases() as $period)
        <flux:radio
            :value="$period->value"
            class="h-7.5 flex-none! border border-transparent px-3! leading-[1.125rem] data-checked:border-zinc-200 data-checked:text-zinc-950! data-checked:shadow-[0_1px_1px_rgb(14_17_22/0.04)]! dark:data-checked:border-white/10 dark:data-checked:text-white!"
            wire:key="billing-period-{{ $period->value }}"
        >
            {{ $period->label() }}

            @if ($period === App\Enums\BillingPeriod::Year)
                <span class="inline-flex h-4.5 items-center rounded-full bg-success-wash px-1.5 text-xs leading-4 font-medium text-success">{{ __('Save :amount', ['amount' => App\Billing\Pesos::rounded(App\Enums\Plan::Pro->yearlySaving())]) }}</span>
            @endif
        </flux:radio>
    @endforeach
</flux:radio.group>
