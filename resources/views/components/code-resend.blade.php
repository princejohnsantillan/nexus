{{--
    The row under an <x-code-input>: a countdown until another code can be
    sent (`seconds`, from the server), then a link that sends one by calling
    the Livewire `action`, with the other way out (another method, another
    address) in the slot on the right. After a wrong code, pass `failed`:
    sending a new code then comes first, in the accent, and the slot is
    expected to step back.

    <x-code-resend :seconds="$this->secondsUntilResend" action="resend" :failed="$errors->has('code')">
        <flux:link :href="route('auth.sign-in')">{{ __('Use another method') }}</flux:link>
    </x-code-resend>
--}}
@props([
    'seconds',
    'action',
    'failed' => false,
])

@php
    $seconds = max(0, (int) $seconds);
@endphp

<div
    wire:key="code-resend-{{ $action }}-{{ $seconds }}-{{ $failed ? 'failed' : 'waiting' }}"
    x-data="{
        left: {{ $seconds }},
        timer: null,
        init() { this.timer = setInterval(() => { this.left = Math.max(0, this.left - 1) }, 1000) },
        destroy() { clearInterval(this.timer) },
        clock() { return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0') },
    }"
    {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-zinc-200 pt-5 text-sm dark:border-white/10') }}
>
    <div>
        <span x-show="left > 0" @style(['display: none' => $seconds === 0]) class="text-zinc-500 dark:text-zinc-400">
            {{ $failed ? __('Send a new code in') : __('Didn\'t get it? Resend in') }}
            <span x-text="clock()" class="tabular-nums">{{ intdiv($seconds, 60) }}:{{ Str::padLeft((string) ($seconds % 60), 2, '0') }}</span>
        </span>

        <span x-show="left === 0" @style(['display: none' => $seconds > 0])>
            @if ($failed)
                <flux:link as="button" variant="ghost" wire:click="{{ $action }}">{{ __('Send a new code') }}</flux:link>
            @else
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Didn\'t get it?') }}</span>
                <flux:link as="button" variant="ghost" wire:click="{{ $action }}">{{ __('Resend code') }}</flux:link>
            @endif
        </span>
    </div>

    {{ $slot }}
</div>
