@blaze(fold: true)

{{-- A ring with a turning arc, for waiting on something outside Nexus. Heroicons has none, and Flux's own "loading" is drawn heavier, so it is drawn here in Flux's custom icon format. It turns unless the viewer prefers less motion. --}}

@props([
    'variant' => 'outline',
])

@php
if ($variant === 'solid') {
    throw new \Exception('The "solid" variant is not supported by the spinner icon.');
}

$classes = Flux::classes('shrink-0 motion-safe:animate-spin')
    ->add(match($variant) {
        'outline' => '[:where(&)]:size-6',
        'mini' => '[:where(&)]:size-5',
        'micro' => '[:where(&)]:size-4',
    });
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2.4"
    stroke-linecap="round"
    aria-hidden="true"
    data-slot="icon"
>
    <circle cx="12" cy="12" r="8" class="opacity-15" />
    <path d="M12 4a8 8 0 0 1 8 8" />
</svg>
