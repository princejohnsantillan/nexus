{{--
    A banner for each of a Star's Connections that needs attention: what is
    wrong, how many of the Star's tools are unavailable, and the fix.
    <x-star-header> shows it under every Star page's header.
--}}
@props([
    'star',
])

@php($problems = app(App\Stars\ConnectionProblems::class)->forStar($star))

@if ($problems !== [])
    <div {{ $attributes->class('space-y-3') }}>
        @foreach ($problems as $problem)
            <flux:callout icon="exclamation-triangle" :color="$problem->connection->status->color()" :heading="$problem->headline()" wire:key="star-problem-{{ $problem->connection->id }}" data-star-problem="{{ $problem->connection->handle }}">
                <flux:callout.text>
                    {{ $problem->reason() }}
                    {{ trans_choice('{0} None of its tools are on in this Star.|{1} :count of this Star\'s tools is unavailable until it\'s fixed.|[2,*] :count of this Star\'s tools are unavailable until it\'s fixed.', $problem->unavailableToolsIn($star)) }}
                </flux:callout.text>

                <x-slot name="actions">
                    <flux:button size="sm" :href="$problem->fixUrl()">{{ $problem->fixLabel(withName: true) }}</flux:button>
                </x-slot>
            </flux:callout>
        @endforeach
    </div>
@endif
