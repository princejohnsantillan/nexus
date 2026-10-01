<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('Connections') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Each Connection is one of your accounts on a remote MCP server.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <x-empty-state icon="link" :heading="__('No Connections yet')">
        {{ __('Connect GitHub, Notion, Linear or any remote MCP server once, then use it in as many Stars as you like.') }}
    </x-empty-state>
</div>
