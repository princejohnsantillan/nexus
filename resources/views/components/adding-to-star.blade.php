{{--
    Says the user is adding a Connection to a Star, from its overview, and
    will go back to it once the Connection is connected (App\Stars\ReturnToStar).
    Pass cancel to offer the way back to the Star without adding anything.
--}}
@props([
    'star',
    'cancel' => false,
])

<div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-2 bg-accent-wash text-sm text-accent-content') }} data-adding-to-star>
    <div class="flex min-w-0 flex-1 items-start gap-2.5">
        <flux:icon.star variant="micro" class="mt-0.5 size-4 shrink-0" />
        <p class="min-w-0 wrap-anywhere">{{ __('Adding to :star. You\'ll go back to :star when it\'s connected.', ['star' => $star->name]) }}</p>
    </div>

    @if ($cancel)
        <flux:button size="sm" variant="ghost" :href="route('stars.show', $star)" class="-my-1 ms-auto" wire:navigate data-adding-to-star-cancel>
            {{ __('Cancel') }}<span class="sr-only">{{ __(', back to :star', ['star' => $star->name]) }}</span>
        </flux:button>
    @endif
</div>
