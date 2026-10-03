{{--
    How a call ended, as the round icon that leads its row in the log: a
    tick on green for OK, a slash in a ring for denied, a key on amber for
    needs sign-in, a clock on amber for timed out and a cross on red for an
    error. It is decorative, so say the status in text beside it.
--}}
@props([
    'status',
])

<span {{ $attributes->class([
    'flex size-[18px] shrink-0 items-center justify-center rounded-full',
    'bg-success-wash text-success' => $status === App\Enums\ActivityStatus::Ok,
    'border-[1.5px] border-zinc-600 bg-white text-zinc-600 dark:border-zinc-400 dark:bg-transparent dark:text-zinc-400' => $status === App\Enums\ActivityStatus::Denied,
    'bg-warning-wash text-warning' => in_array($status, [App\Enums\ActivityStatus::NeedsAuth, App\Enums\ActivityStatus::Timeout], true),
    'bg-danger text-white dark:text-zinc-950' => $status === App\Enums\ActivityStatus::Error,
]) }} aria-hidden="true" data-status="{{ $status->value }}">
    @switch ($status)
        @case (App\Enums\ActivityStatus::Ok)
            <svg class="size-2.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5" /></svg>
            @break
        @case (App\Enums\ActivityStatus::Denied)
            <svg class="size-2.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 13L13 3" /></svg>
            @break
        @case (App\Enums\ActivityStatus::NeedsAuth)
            <svg class="size-2.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="5.5" cy="10.5" r="3" /><path d="M7.7 8.3L13 3m-2 2l1.5 1.5" /></svg>
            @break
        @case (App\Enums\ActivityStatus::Timeout)
            <svg class="size-[11px]" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="8" cy="8" r="6" /><path d="M8 5v3.2l2 1.3" /></svg>
            @break
        @case (App\Enums\ActivityStatus::Error)
            <svg class="size-[9px]" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8" /></svg>
            @break
    @endswitch
</span>
