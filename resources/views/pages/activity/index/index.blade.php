<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('Activity') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Every tool call and prompt fetch made through your Stars.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <x-empty-state icon="queue-list" :heading="__('No activity yet')">
        {{ __('When an AI client uses one of your Stars, each call appears here with its time, status and duration. Arguments and results are never stored.') }}
    </x-empty-state>
</div>
