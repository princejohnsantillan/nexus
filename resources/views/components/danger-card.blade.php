{{--
    A section card for a destructive action, such as deleting something: a
    red-tinted footer whose hint says it can't be undone, unless you pass
    your own, and the action on the right.
--}}
@props([
    'heading',
    'description' => null,
])

<x-section-card :heading="$heading" :description="$description" danger {{ $attributes }}>
    {{ $slot }}

    <x-slot:hint>{{ $hint ?? __('This can\'t be undone.') }}</x-slot:hint>

    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-section-card>
