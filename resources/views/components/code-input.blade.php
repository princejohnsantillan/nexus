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

<flux:field>
    @if (filled($label))
        <flux:label>{{ $label }}</flux:label>
    @endif

    <flux:otp
        :attributes="$attributes->class('gap-1.5 sm:gap-2 [&_[data-flux-input]]:w-10! sm:[&_[data-flux-input]]:w-13!')"
        submit="auto"
        :aria-label="$label"
    >
        @for ($box = 0; $box < 3; $box++)
            <flux:otp.input :invalid="$invalid" class:input="h-12! px-0! text-center font-mono text-xl! text-zinc-950 data-invalid:bg-danger-wash sm:h-15! sm:text-2xl! dark:text-white" />
        @endfor

        <span class="mx-0.5 h-px w-2.5 shrink-0 bg-zinc-300 sm:mx-1 sm:w-3 dark:bg-zinc-600" aria-hidden="true"></span>

        @for ($box = 0; $box < 3; $box++)
            <flux:otp.input :invalid="$invalid" class:input="h-12! px-0! text-center font-mono text-xl! text-zinc-950 data-invalid:bg-danger-wash sm:h-15! sm:text-2xl! dark:text-white" />
        @endfor
    </flux:otp>

    @if ($invalid)
        <flux:error :name="$name" icon="exclamation-circle" class="mt-2.5 text-danger!" />
    @elseif (filled($hint))
        <flux:text size="sm" class="mt-2.5 text-zinc-500 dark:text-zinc-400">{{ $hint }}</flux:text>
    @endif
</flux:field>
