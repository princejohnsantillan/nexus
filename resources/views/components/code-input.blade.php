{{--
    A one-time code in six boxes, split 3 + 3 (Flux's OTP input), in the
    mono face. Pasting fills every box, and the form it's in submits as soon
    as the last box is filled. Bind it with wire:model. While that property
    has an error the boxes turn red and keep their digits, and the error
    shows under them; otherwise the hint does.

    <x-code-input wire:model="code" :label="__('Sign-in code')" :hint="__('Paste or type it.')" />
--}}
@props([
    'label' => null,
    'hint' => null,
])

@php
    $name = $attributes->wire('model')->value();
    $invalid = filled($name) && $errors->has($name);
@endphp

<flux:field class="[&>[data-flux-label]]:flex">
    @if (filled($label))
        <flux:label class="text-[0.8125rem]! leading-4 text-zinc-950 dark:text-white">{{ $label }}</flux:label>
    @endif

    <flux:otp
        :attributes="$attributes->class('gap-1.5 sm:gap-2 [&_[data-flux-input]]:w-10! sm:[&_[data-flux-input]]:w-13! [&_input]:border-zinc-300 dark:[&_input]:border-white/10')"
        submit="auto"
        :aria-label="$label"
    >
        @for ($box = 0; $box < 3; $box++)
            <flux:otp.input :invalid="$invalid" class:input="h-12! px-0! text-center font-mono text-xl! font-medium text-zinc-950 data-invalid:bg-danger-wash sm:h-15! sm:text-2xl! dark:text-white" />
        @endfor

        <span class="mx-0.5 h-px w-2.5 shrink-0 bg-zinc-300 sm:mx-0 sm:h-0.5 sm:w-3 dark:bg-zinc-600" aria-hidden="true"></span>

        @for ($box = 0; $box < 3; $box++)
            <flux:otp.input :invalid="$invalid" class:input="h-12! px-0! text-center font-mono text-xl! font-medium text-zinc-950 data-invalid:bg-danger-wash sm:h-15! sm:text-2xl! dark:text-white" />
        @endfor
    </flux:otp>

    @if ($invalid)
        <flux:error :name="$name" icon="exclamation-circle" class="mt-3 flex items-start gap-2 text-[0.8125rem]! leading-5 font-normal! text-danger! [&>[data-flux-icon]]:size-4" />
    @elseif (filled($hint))
        <flux:text class="mt-3 text-[0.8125rem] leading-4 text-zinc-600 dark:text-zinc-400">{{ $hint }}</flux:text>
    @endif
</flux:field>
