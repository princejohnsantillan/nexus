{{--
    The upgrade prompt at a Free limit (board P3): a Flux modal named
    "star-limit" or "connection-limit", for `for="stars"` or
    `for="connections"`. It says the user has used every Star or Connection
    Free includes, sums up Pro, and offers "Not now" and "See Pro" (the
    Upgrade page, Yearly picked). Render it only at the limit, which Pro
    never reaches, and open it from what would add another with a
    `flux:modal.trigger` of its name. Closing it puts focus back where it
    was, on the button that opened it.
--}}
@props([
    'for',
])

@php
$isStars = $for === 'stars';
$name = $isStars ? 'star-limit' : 'connection-limit';
$limit = ($isStars ? App\Enums\Plan::Free->starLimit() : App\Enums\Plan::Free->connectionLimit()) ?? 0;
$pro = App\Enums\Plan::Pro;

$heading = $isStars
    ? trans_choice('{1} You\'ve used your Free Star|{2} You\'ve used both Free Stars|[0,*] You\'ve used all :count Free Stars', $limit)
    : trans_choice('{1} You\'ve used your Free Connection|{2} You\'ve used both Free Connections|[0,*] You\'ve used all :count Free Connections', $limit);
$description = $isStars
    ? trans_choice('Free includes :count Star. Go Pro for as many as your agents need, or delete a Star you no longer use.|Free includes :count Stars. Go Pro for as many as your agents need, or delete a Star you no longer use.', $limit)
    : trans_choice('Free includes :count Connection. Go Pro for as many as you need, or delete one you no longer use.|Free includes :count Connections. Go Pro for as many as you need, or delete one you no longer use.', $limit);
@endphp

<flux:modal
    :name="$name"
    :closable="false"
    class="w-[calc(100%-2rem)] max-w-110 overflow-clip border border-zinc-200 p-0! shadow-[0_16px_48px_rgb(14_17_22/0.16),0_2px_6px_rgb(14_17_22/0.08)]! ring-0! backdrop:bg-zinc-950/25! dark:border-zinc-700 dark:backdrop:bg-black/50!"
    data-limit-modal="{{ $for }}"
>
    <div x-init="$el.closest('dialog').setAttribute('aria-labelledby', '{{ $name }}-heading'); $el.closest('dialog').setAttribute('aria-describedby', '{{ $name }}-description')" class="flex flex-col gap-4 p-6">
        <div class="flex items-start justify-between">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-accent-wash">
                <flux:icon :icon="$isStars ? 'star' : 'link'" variant="solid" class="size-5 text-accent-content" />
            </div>

            <flux:modal.close class="-me-2 -mt-2 flex">
                <flux:button variant="ghost" size="sm" icon="x-mark" icon:variant="micro" class="text-zinc-500! hover:text-zinc-800! dark:text-zinc-400! dark:hover:text-white!" :aria-label="__('Close')" />
            </flux:modal.close>
        </div>

        <div class="flex flex-col gap-1.5">
            <h2 id="{{ $name }}-heading" class="text-xl/7 font-semibold tracking-[-0.011em] text-zinc-950 dark:text-white">{{ $heading }}</h2>
            <p id="{{ $name }}-description" class="text-sm/5.5 text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        </div>

        <div class="flex flex-col gap-2.5 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3.5 dark:border-white/10 dark:bg-white/5" data-limit-modal-pro>
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p class="text-sm/4.5 font-semibold text-zinc-950 dark:text-white">{{ $pro->label() }}</p>
                <p class="text-[13px]/4 text-zinc-600 dark:text-zinc-400">{{ __(':monthly / month or :yearly / year', ['monthly' => App\Billing\Pesos::rounded($pro->price(App\Enums\BillingPeriod::Month)), 'yearly' => App\Billing\Pesos::rounded($pro->price(App\Enums\BillingPeriod::Year))]) }}</p>
            </div>

            <ul class="flex flex-wrap gap-x-4 gap-y-2">
                @foreach ([__('Unlimited Stars'), __('Unlimited Connections')] as $line)
                    <li class="flex items-center gap-1.5 text-[13px]/4 text-zinc-950 dark:text-white">
                        <flux:icon.check variant="micro" class="size-3.5 text-accent-content" />
                        {{ $line }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2 border-t border-zinc-200 bg-zinc-50 px-6 py-3.5 dark:border-white/10 dark:bg-black/15">
        <flux:modal.close>
            <flux:button class="h-8.5! border-zinc-300! px-3.5! dark:border-zinc-600!">{{ __('Not now') }}</flux:button>
        </flux:modal.close>

        {{-- Close the modal before leaving, so going back doesn't show it again. --}}
        <flux:button
            variant="primary"
            class="h-8.5! px-3.5!"
            :href="route('billing.upgrade', ['period' => App\Enums\BillingPeriod::Year->value])"
            x-on:click="if (! ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey)) { $event.preventDefault(); $flux.modal('{{ $name }}').close(); Livewire.navigate($el.href) }"
            data-limit-modal-upgrade
        >{{ __('See Pro') }}</flux:button>
    </div>
</flux:modal>
