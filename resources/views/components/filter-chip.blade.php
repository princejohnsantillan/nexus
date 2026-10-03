{{--
    A filter as a chip that opens its menu of choices: a dashed "+ Star"
    while it's unset, and "Star Work" on the accent wash once a value is
    chosen. Put a flux:menu in the slot, usually a flux:menu.radio.group
    bound with wire:model.live.
--}}
@props([
    'label',
    'value' => null,
])

<flux:dropdown {{ $attributes }}>
    <button type="button" @class([
        'inline-flex h-[30px] max-w-full items-center gap-1.5 rounded-full border px-2.5 text-sm',
        'border-dashed border-zinc-300 text-zinc-600 hover:border-zinc-400 hover:text-zinc-950 dark:border-white/20 dark:text-zinc-300 dark:hover:border-white/40 dark:hover:text-white' => $value === null,
        'border-accent/25 bg-accent-wash text-accent-content' => $value !== null,
    ])>
        @if ($value === null)
            <flux:icon.plus variant="micro" class="size-3" />
            {{ $label }}
        @else
            <span>{{ $label }}</span>
            <span class="min-w-0 truncate font-medium">{{ $value }}</span>
            <flux:icon.chevron-down variant="micro" class="size-3.5" />
        @endif
    </button>

    {{ $slot }}
</flux:dropdown>
