<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('Stars') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Each Star is one MCP server endpoint that bundles some of your Connections.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <x-empty-state icon="star" :heading="__('No Stars yet')">
        {{ __('Create a Star, choose the Connections it includes and switch each tool on or off. Then add it once to Claude Code, claude.ai, Codex, Cursor or Grok.') }}
    </x-empty-state>
</div>
