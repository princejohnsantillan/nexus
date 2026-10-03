{{--
    A "Not saved" pill for something changed on the page since the last
    save, such as a Connection ticked or unticked on a Star's overview.
--}}
<span {{ $attributes->class('inline-flex h-5.5 shrink-0 items-center gap-1.5 rounded-full bg-white px-2 text-xs font-medium whitespace-nowrap text-accent-content ring-1 ring-accent/25 ring-inset dark:bg-white/5 dark:ring-accent/40') }} data-not-saved>
    <span class="size-1.5 shrink-0 rounded-full bg-accent"></span>
    {{ __('Not saved') }}
</span>
