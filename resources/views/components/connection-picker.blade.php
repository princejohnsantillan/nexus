{{--
    Checkbox cards for choosing which of the user's Connections a Star
    includes. Bind it like a checkbox group, to an array of Connection ids:

    <x-connection-picker :connections="$connections" wire:model="connectionIds" />

    Each Connection needs `tools_count` loaded (`withCount('tools')`). Pass
    `unsaved` with the ids of the Connections ticked or unticked since the
    last save to mark them "Not saved".
--}}
@props([
    'connections',
    'unsaved' => [],
])

<flux:checkbox.group variant="cards" {{ $attributes->class('flex-col') }}>
    @foreach ($connections as $connection)
        <flux:checkbox :value="(string) $connection->id" wire:key="connection-picker-{{ $connection->id }}">
            <div class="flex min-w-0 flex-1 gap-3">
                <x-connector-logo :connector="$connection->connector()" size="sm" />

                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-center gap-2">
                        <flux:heading class="truncate">{{ $connection->name }}</flux:heading>

                        @if (in_array($connection->id, $unsaved, true))
                            <x-not-saved class="sm:hidden" />
                        @endif
                    </div>

                    <flux:text size="sm" class="mt-0.5 truncate">
                        <span class="font-mono">{{ $connection->handle }}</span>
                        · {{ trans_choice(':count tool|:count tools', $connection->tools_count ?? 0) }}
                        <x-account-label :connection="$connection" separated />
                    </flux:text>
                </div>
            </div>

            @if (in_array($connection->id, $unsaved, true))
                <x-not-saved class="self-center max-sm:hidden" />
            @endif

            <flux:checkbox.indicator class="mt-px" />
        </flux:checkbox>
    @endforeach
</flux:checkbox.group>
