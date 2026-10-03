{{--
    A small trend line drawn from a list of numbers, oldest first, in the
    accent colour. It is decorative: show the number it summarises as text.
--}}
@props([
    'values',
])

@php
$values = array_values($values);

if (count($values) === 1) {
    $values[] = $values[0];
}

$high = $values === [] ? 0 : max($values);
$low = $values === [] ? 0 : min($values);

// A flat line sits in the middle rather than along the top edge.
if ($high - $low == 0) {
    $high += 1;
    $low -= 1;
}

$points = collect($values)->map(fn ($value, $index) => $index.','.($high - $value))->implode(' ');
$padding = ($high - $low) / 10;
$viewBox = implode(' ', [0, -$padding, max(count($values) - 1, 1), ($high - $low) + 2 * $padding]);
@endphp

@if ($values !== [])
    <svg {{ $attributes->class('h-5.5 w-16 shrink-0 overflow-visible text-accent') }} viewBox="{{ $viewBox }}" preserveAspectRatio="none" fill="none" aria-hidden="true" data-sparkline>
        <polyline points="{{ $points }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
@endif
