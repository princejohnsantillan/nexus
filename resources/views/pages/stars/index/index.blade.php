<div class="mx-auto w-full max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Stars') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Each Star is one MCP server endpoint that bundles some of your Connections.') }}</flux:text>
        </div>

        @if ($this->stars->isNotEmpty())
            <flux:modal.trigger name="create-star">
                <flux:button variant="primary" icon="plus" :disabled="$this->limitMessage !== null">{{ __('Create Star') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->limitMessage !== null)
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6" :heading="__('Star limit reached')">
            <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->stars->isEmpty())
        <x-empty-state icon="star" :heading="__('No Stars yet')">
            {{ __('Create a Star, choose the Connections it includes and switch each tool on or off. Then add it once to Claude Code, claude.ai, Codex, Cursor or Grok.') }}

            <x-slot:actions>
                <flux:modal.trigger name="create-star">
                    <flux:button variant="primary" icon="plus">{{ __('Create your first Star') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>
        </x-empty-state>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Connections') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Tools on') }}</flux:table.column>
                <flux:table.column>{{ __('Access') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->stars as $star)
                    <flux:table.row :key="$star->id">
                        <flux:table.cell>
                            <flux:link :href="route('stars.show', $star)" wire:navigate class="font-medium">{{ $star->name }}</flux:link>

                            @if (filled($star->description))
                                <flux:text size="sm" class="mt-0.5 max-w-xs truncate">{{ $star->description }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-w-xs whitespace-normal!">
                            @if ($star->connections->isEmpty())
                                <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('None yet') }}</flux:text>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($star->connections as $connection)
                                        <flux:badge size="sm" class="gap-1.5" wire:key="star-{{ $star->id }}-connection-{{ $connection->id }}"><x-connector-logo :connector="$connection->connector()" size="xs" />{{ $connection->name }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ __(':enabled of :total', $this->toolCounts[$star->id]) }}</flux:table.cell>
                        <flux:table.cell>{{ $star->access_mode->label() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="create-star" class="w-full max-w-xl">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a Star') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Its read-only tools start on and everything else off. You can change any of that on its Tools page.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :description="__('What you call this Star, e.g. after the client or the job it is for.')" placeholder="Work" maxlength="100" />

            <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" :description="__('Agents see this, so say what the Star is for.')" rows="2" maxlength="500" />

            <flux:radio.group wire:model="accessMode" variant="cards" class="flex-col" :label="__('Access mode')" :description="__('How clients authenticate to the Star. You can change it later on its Access page.')">
                @foreach (App\Enums\StarAccessMode::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="access-mode-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>

            @if ($this->connections->isEmpty())
                <flux:callout icon="link" :heading="__('No Connections yet')">
                    <flux:callout.text>{{ __('You can create the Star now and add Connections to it later.') }}</flux:callout.text>

                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('connections.add')" wire:navigate>{{ __('Add connection') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @else
                <x-connection-picker :connections="$this->connections" wire:model="connectionIds" :label="__('Connections')" :description="__('The Star includes the tools of the Connections you choose.')" />
            @endif

            <flux:error name="limit" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Create Star') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
