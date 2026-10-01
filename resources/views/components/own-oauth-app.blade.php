{{--
    Optional fields for an OAuth app the user registered on a custom server,
    for servers that can't register Nexus by themselves. Bind `clientId` and
    `clientSecret` on the component. `hasStoredSecret` says a secret is
    stored, so a blank field keeps it.
--}}
@props([
    'callbackUrl',
    'hasStoredSecret' => false,
])

<div class="space-y-6">
    <flux:text>{{ __('Nexus registers itself with servers that allow it. If this one doesn\'t, register an OAuth app on it with the callback URL below and enter the app\'s client ID, and its secret if it has one.') }}</flux:text>

    <flux:input :value="$callbackUrl" readonly copyable :label="__('Callback URL')" class:input="font-mono" />

    <div class="grid gap-6 sm:grid-cols-2">
        <flux:input wire:model="clientId" :label="__('Client ID')" :badge="__('Optional')" autocomplete="off" autocapitalize="off" spellcheck="false" class:input="font-mono" />

        <flux:input
            wire:model="clientSecret"
            type="password"
            viewable
            :label="__('Client secret')"
            :badge="__('Optional')"
            :description="$hasStoredSecret ? __('Stored encrypted. Leave blank to keep the current secret.') : __('Stored encrypted.')"
            autocomplete="off"
        />
    </div>
</div>
