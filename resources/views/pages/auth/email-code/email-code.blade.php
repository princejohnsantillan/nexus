<x-sign-in-frame>
    <div class="flex size-9 items-center justify-center rounded-full bg-accent-wash text-accent-content" aria-hidden="true">
        <flux:icon.envelope variant="outline" class="size-4" />
    </div>

    <flux:heading level="1" class="mt-6 text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950 dark:text-white">{{ __('Check your inbox') }}</flux:heading>

    <flux:text class="mt-2.5 leading-[1.375rem] break-words text-zinc-600 dark:text-zinc-400">
        {{ __('We sent a 6-digit code to :email. It expires in :minutes minutes.', ['email' => $email, 'minutes' => App\Auth\EmailCodes::MINUTES_VALID]) }}
    </flux:text>

    <form wire:submit="signIn" class="mt-7">
        <x-code-input wire:model="code" :label="__('Sign-in code')" :hint="__('Paste or type it. You\'re signed in as soon as it\'s complete.')" />

        <button type="submit" class="sr-only">{{ __('Sign in') }}</button>
    </form>

    <x-code-resend :seconds="$this->secondsUntilResend" action="resend" :failed="$errors->has('code')" class="mt-9">
        <flux:link :href="route('auth.sign-in')" :variant="$errors->has('code') ? 'subtle' : 'ghost'">{{ __('Use another method') }}</flux:link>
    </x-code-resend>
</x-sign-in-frame>
